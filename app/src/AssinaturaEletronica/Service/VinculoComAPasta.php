<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\Armazenamento\ChavesDeAssinaturaEletronica;
use App\AssinaturaEletronica\Entity\DocumentoParaAssinar;
use App\AssinaturaEletronica\Enum\SituacaoDoDocumento;
use App\AssinaturaEletronica\Enum\TipoDeEvento;
use App\AssinaturaEletronica\Message\GerarPdfFinalDoDocumento;
use App\AssinaturaEletronica\Repository\DocumentoParaAssinarRepository;
use App\AssinaturaEletronica\Repository\EventoDaTrilhaRepository;
use App\AssinaturaEletronica\Service\Selo\CadeiaDoSeloPendente;
use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Service\PermissionChecker;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Aplica o vínculo pendente (spec assinatura-documentos-etapa-3, E3-11): com o documento concluído, o PDF final com selo
 * vai para a pasta escolhida. Chamado pelo "Vincular à pasta" de um documento já concluído e pelo
 * `VinculoPendenteListener` quando o PDF final é guardado.
 *
 * O PDF final nunca é gerado aqui (pesa, e esta requisição não passa pelas vagas nem pelo limitador do download): sem
 * ele guardado, o pedido vai ao worker ({@see GerarPdfFinalDoDocumento}) e o vínculo segue pendente, aplicado pelo
 * listener quando o worker guardar. Com a cadeia do selo pendente ({@see CadeiaDoSeloPendente}), o worker já tenta o
 * selo desse documento: nada vai à fila, e o vínculo segue pendente do mesmo jeito (spec assinatura-documentos-etapa-8,
 * E8-4). Fila fora do ar: "vínculo não aplicado" com o motivo do PDF indisponível.
 *
 * Tudo é conferido de novo com QUEM VINCULOU (não com quem está na tela): pasta que sumiu, pasta na lápide, usuário
 * removido, sem permissão de editar a pasta ou fora do cadeado. Na recusa (e na falha do PDF final ou da cópia) nada é
 * gravado na pasta, a trilha ganha "vínculo não aplicado" com o motivo e o vínculo pendente se desfaz: o "Vincular à
 * pasta" volta, como no desenho (`vincExec` não grava o vínculo na falha). Não é "desvincular" (E3-10): o vínculo feito
 * nunca é desfeito.
 *
 * Idempotente: vínculo já feito não grava de novo; duas chamadas ao mesmo tempo não duplicam o arquivo, pela trava da
 * linha (entre processos) e pela marca de "em andamento" (na mesma requisição).
 */
final class VinculoComAPasta
{
    /** @var array<int, true> documentos com aplicação em andamento nesta requisição */
    private array $emAndamento = [];

    public function __construct(
        private readonly DocumentoParaAssinarRepository $documentos,
        private readonly EventoDaTrilhaRepository $eventos,
        private readonly TrilhaDoDocumento $trilha,
        private readonly PermissionChecker $permissoes,
        private readonly PdfFinalDoDocumento $pdfFinal,
        private readonly MessageBusInterface $mensagens,
        private readonly CadeiaDoSeloPendente $cadeiaDoSelo,
        private readonly ArquivoAssinadoNaPasta $arquivos,
        private readonly TranslatorInterface $tradutor,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Verdadeiro quando o documento está na pasta (agora ou antes); falso quando não há vínculo, quando ele segue
     * pendente (documento ainda aguardando, ou o PDF final ainda não guardado) ou quando a aplicação falhou e o vínculo
     * se desfez.
     */
    public function aplicar(DocumentoParaAssinar $documento): bool
    {
        if (!$documento->isVinculoPendente()) {
            return $documento->getPasta() !== null;
        }
        $id = (int) $documento->getId();
        if ($documento->getSituacao() !== SituacaoDoDocumento::Concluido || isset($this->emAndamento[$id])) {
            return false;
        }

        $this->emAndamento[$id] = true;
        try {
            return $this->tentar($documento);
        } finally {
            unset($this->emAndamento[$id]);
        }
    }

    /**
     * O worker desistiu do PDF final (a falha que lança, depois de todas as tentativas do Messenger): o vínculo pendente
     * vira "vínculo não aplicado" com o motivo do PDF indisponível e se desfaz, com o erro no log. Antes de decidir, o
     * documento é relido na trava: a instância vem do mapa de identidade do worker, carregada no começo da última
     * tentativa, e outro processo (o primeiro download) pode ter guardado o PDF final ou aplicado o vínculo nesse meio.
     * Com o PDF guardado, segue o caminho de sempre e vai para a pasta; guardado só na trava da falha, nada é desfeito
     * (o evento do PDF guardado aplica o vínculo no outro processo). Sem vínculo pendente, ou com o documento ainda
     * aguardando, nada muda.
     *
     * @return bool verdadeiro só quando o documento está na pasta
     */
    public function pdfFinalFalhou(DocumentoParaAssinar $documento, \Throwable $erro): bool
    {
        if (!$this->documentos->emTransacao(fn (): bool => $this->documentos->travarERecarregar($documento, $documento->getTenant()))) {
            return false;
        }
        if (!$documento->isVinculoPendente()
            || $documento->getSituacao() !== SituacaoDoDocumento::Concluido
            || ChavesDeAssinaturaEletronica::pdfFinal($documento) !== null) {
            return $this->aplicar($documento);
        }

        return $this->falharComErro($documento, $documento->getPasta(), 'pdf_indisponivel', $erro, semPdfGuardado: true);
    }

    private function tentar(DocumentoParaAssinar $documento): bool
    {
        $pasta = $documento->getPasta();
        $autor = $documento->getVinculadoPor();
        $motivo = $this->impedimento($pasta, $autor, $documento);
        if ($motivo !== null || $pasta === null || $autor === null) {
            return $this->falhar($documento, $pasta, $motivo ?? 'pasta_apagada');
        }

        // Sem o PDF final guardado: o worker gera e guarda, e o evento traz o vínculo de volta para cá.
        if (ChavesDeAssinaturaEletronica::pdfFinal($documento) === null) {
            return $this->pedirPdfAoWorker($documento, $pasta);
        }

        // Com ele guardado, o `garantir` só devolve a chave.
        try {
            $pdf = $this->pdfFinal->garantir($documento);
        } catch (\Throwable $e) {
            return $this->falharComErro($documento, $pasta, 'pdf_indisponivel', $e);
        }
        try {
            $arquivo = $this->arquivos->copiar($documento, $pasta, $autor, $pdf);
        } catch (\Throwable $e) {
            return $this->falharComErro($documento, $pasta, 'falha_ao_salvar', $e);
        }

        if (!$this->gravar($documento, $arquivo, $autor)) {
            $this->arquivos->descartar($arquivo);

            // Outro processo resolveu antes: aplicado só se o vínculo ficou feito (com pasta). Se ele falhou e desfez
            // o vínculo, a pasta é nula e o documento não está na pasta.
            return !$documento->isVinculoPendente() && $documento->getPasta() !== null;
        }
        $this->arquivos->depoisDeGuardar($arquivo, $documento, $autor);

        return true;
    }

    /**
     * Pede o PDF final ao worker: o vínculo segue pendente (falso); fila fora do ar desfaz como PDF indisponível. Com a
     * cadeia do selo pendente, o worker já tem o pedido: não abre outra série de tentativas.
     */
    private function pedirPdfAoWorker(DocumentoParaAssinar $documento, Pasta $pasta): bool
    {
        $id = (int) $documento->getId();
        if ($this->cadeiaDoSelo->estaPendente($id)) {
            return false;
        }

        try {
            $this->mensagens->dispatch(new GerarPdfFinalDoDocumento($id, (int) $documento->getTenant()->getId()));
        } catch (\Throwable $e) {
            return $this->falharComErro($documento, $pasta, 'pdf_indisponivel', $e);
        }

        return false;
    }

    /** A chave do motivo da recusa, ou nulo quando quem vinculou ainda pode gravar na pasta. */
    private function impedimento(?Pasta $pasta, ?User $autor, DocumentoParaAssinar $documento): ?string
    {
        return match (true) {
            $pasta === null => 'pasta_apagada',
            $pasta->estaExcluida() => 'pasta_excluida',
            $autor === null || !$autor->isActive() => 'sem_quem_vinculou',
            !$this->permissoes->canAccessResource($autor, $documento->getTenant(), AccessRequest::RESOURCE_PASTA, (int) $pasta->getId(), AccessRequest::ACTION_EDIT) => 'sem_permissao',
            default => null,
        };
    }

    /** Na transação, com a linha travada e relida: só grava se o vínculo ainda está pendente para a mesma pasta. */
    private function gravar(DocumentoParaAssinar $documento, PastaDocumento $arquivo, User $autor): bool
    {
        return $this->documentos->emTransacaoComArquivoNovo(function () use ($documento, $arquivo, $autor): bool {
            if (!$this->documentos->travarERecarregar($documento, $documento->getTenant())
                || !$documento->isVinculoPendente()
                || $documento->getPasta() !== $arquivo->getPasta()) {
                return false;
            }
            $this->arquivos->guardar($arquivo, $documento, $autor);
            $documento->marcarVinculoFeito($arquivo);
            $this->trilha->acrescentar($documento, TipoDeEvento::VinculoFeito, ['nup' => (string) $arquivo->getPasta()?->getNup()], $autor);

            return true;
        }, fn (): array => $this->arquivos->chavesNovas($arquivo), ArquivoAssinadoNaPasta::CONTEXTO);
    }

    private function falharComErro(DocumentoParaAssinar $documento, ?Pasta $pasta, string $motivo, \Throwable $erro, bool $semPdfGuardado = false): bool
    {
        $this->logger->error('Vínculo da assinatura: documento {id} não foi para a pasta ({motivo}): {erro}', ['id' => $documento->getId(), 'motivo' => $motivo, 'erro' => $erro->getMessage()]);

        return $this->falhar($documento, $pasta, $motivo, $semPdfGuardado);
    }

    /**
     * "Vínculo à pasta {nup} não aplicado: {motivo}" na trilha, em nome de quem vinculou, e o vínculo pendente desfeito.
     * Na transação, com a linha travada e relida: se outro processo já aplicou o vínculo, ou ele já não é o da pasta
     * desta tentativa, nada é gravado nem desfeito. O número e quem vinculou são lidos antes de desfazer.
     *
     * @param Pasta|null $pastaDaTentativa a pasta lida no começo da tentativa (nula quando já tinha sido apagada)
     * @param bool       $semPdfGuardado   a falha é a falta do PDF final: relido já com ele guardado, nada é desfeito
     *
     * @return bool verdadeiro só quando, relido, o documento já está na pasta (aplicado por outro processo)
     */
    private function falhar(DocumentoParaAssinar $documento, ?Pasta $pastaDaTentativa, string $motivo, bool $semPdfGuardado = false): bool
    {
        $texto = $this->tradutor->trans('assinatura_eletronica.vinculo_motivo.' . $motivo);
        $this->documentos->emTransacao(function () use ($documento, $pastaDaTentativa, $texto, $semPdfGuardado): void {
            if (!$this->documentos->travarERecarregar($documento, $documento->getTenant())
                || !$documento->isVinculoPendente()
                || $documento->getPasta()?->getId() !== $pastaDaTentativa?->getId()
                || ($semPdfGuardado && ChavesDeAssinaturaEletronica::pdfFinal($documento) !== null)) {
                return;
            }
            $nup = $documento->getPasta()?->getNup() ?? $this->nupDaTrilha($documento);
            $quemVinculou = $documento->getVinculadoPor();
            $this->trilha->acrescentar($documento, TipoDeEvento::VinculoNaoAplicado, ['nup' => $nup, 'motivo' => $texto], $quemVinculou);
            $documento->descartarVinculoNaoAplicado();
        });

        return !$documento->isVinculoPendente() && $documento->getPasta() !== null;
    }

    /** A pasta apagada de vez (SET NULL) não tem mais número: vale o do último evento de vínculo. */
    private function nupDaTrilha(DocumentoParaAssinar $documento): string
    {
        foreach ($this->eventos->doDocumento($documento) as $evento) {
            $nup = $evento->getDados()['nup'] ?? null;
            if (\is_string($nup) && $nup !== '') {
                return $nup;
            }
        }

        return '';
    }
}
