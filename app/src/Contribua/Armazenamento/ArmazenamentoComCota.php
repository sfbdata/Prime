<?php

declare(strict_types=1);

namespace App\Contribua\Armazenamento;

use App\Contribua\DTO\RetratoDoEspaco;
use App\Contribua\Event\EnvioRecusadoPorEspaco;
use App\Contribua\Event\EspacoAtingiuFaixa;
use App\Contribua\Event\EspacoPassouDoContratado;
use App\Contribua\Event\EventosDoEspacoAposConfirmar;
use App\Contribua\Event\UsoDoEspacoSubiu;
use App\Contribua\Exception\EspacoEsgotado;
use App\Contribua\Repository\ConsumoDeArmazenamentoRepository;
use App\Contribua\Service\FolgaDaAmpliacao;
use App\Contribua\Service\SituacaoDoEspaco;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ArquivoArmazenado;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\FonteDeConteudo;
use App\Shared\Armazenamento\MetadadosDeArquivo;
use App\Shared\Armazenamento\NovoArquivo;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Contracts\Service\ResetInterface;

/**
 * O ponto ÚNICO de bloqueio por espaço: todo arquivo novo de escritório passa por aqui.
 *
 * Só olha `NovoArquivo` com escopo de tenant. Passam livres:
 *  - regravação numa chave existente (`ChaveDeArquivo`): a recompressão regrava menor e a edição
 *    de peça regrava o mesmo documento; bloquear ali impediria até liberar espaço;
 *  - escopo global (foto de perfil, que é do User);
 *  - leitura, download, exclusão e metadados: "consultar e baixar arquivos continua liberado".
 *
 * O tamanho de uma `FonteDeConteudo` só é conhecido depois da escrita (stream). Por isso: recusa
 * antes quando o escritório já está no limite; senão grava, mede e, se passou, remove o arquivo
 * recém-cunhado (ainda sem registro que aponte para ele) e recusa. Com o bloqueio desligado nada é
 * recusado (`RetratoDoEspaco::cabe()`), mas o uso continua medido: subir o percentual gera
 * `UsoDoEspacoSubiu` e subir de faixa gera `EspacoAtingiuFaixa`, despachados depois do commit; é deles
 * que saem os avisos por e-mail (frente "Comercial e e-mails").
 *
 * Ampliação de GB automática (desenho 08 VS 03.11.26): com a chave ligada e uma assinatura que ainda pode dobrar, o
 * envio que passaria do limite entra com a folga de UMA dobra e `EspacoPassouDoContratado` sai depois do commit, uma
 * vez por escritório na requisição; quem ouve dobra o espaço e o valor (`AmpliarEspacoAutomaticamenteUseCase`).
 *
 * O uso medido fica guardado por escritório durante a requisição e cresce a cada gravação: um
 * envio de dez arquivos faz uma soma no banco, não dez. `reset()` esvazia entre requisições e
 * mensagens do worker.
 *
 * Limites aceitos, conscientes:
 *  - não é atômico entre requisições: dois envios simultâneos do mesmo escritório medem o mesmo uso e
 *    podem passar juntos do limite por um arquivo. O próximo envio já é recusado;
 *  - o tamanho medido é o gravado: quem comprime depois de gravar (financeiro da pasta) é medido
 *    pelo tamanho cru nesta requisição, o que só erra para o lado de recusar.
 */
#[AsDecorator(decorates: ArmazenamentoDeArquivos::class)]
final class ArmazenamentoComCota implements ArmazenamentoDeArquivos, ResetInterface
{
    /**
     * As categorias que `ConsumoDeArmazenamentoRepository` soma. As demais passam sem bloqueio nem
     * medição: justificativa de ponto (o funcionário nunca pode ficar sem justificar a falta),
     * Trabalho Externo, imagem do editor, anexo de tarefa, foto de perfil e foto do cliente ou da pasta (como a de
     * perfil, sem tamanho no banco: é um JPEG de 320 px, recortado no navegador).
     */
    private const CATEGORIAS_COBRADAS = [
        CategoriaDeArquivo::PASTA_DOCUMENTO,
        CategoriaDeArquivo::CLIENTE_DOCUMENTO,
        CategoriaDeArquivo::CHAMADO_ANEXO,
        CategoriaDeArquivo::COBRANCA_DOCUMENTO,
        CategoriaDeArquivo::KANBAN_ANEXO,
    ];

    /** @var array<int, int> bytes usados por tenant, medidos nesta requisição */
    private array $usados = [];

    /** @var array<int, int> folga da ampliação automática por tenant, lida nesta requisição só quando o envio não cabe */
    private array $folgas = [];

    /** @var array<int, true> tenants cuja ampliação já foi pedida nesta requisição */
    private array $ampliacaoPedida = [];

    public function __construct(
        #[AutowireDecorated]
        private readonly ArmazenamentoDeArquivos $interno,
        private readonly ConsumoDeArmazenamentoRepository $consumo,
        private readonly SituacaoDoEspaco $situacao,
        private readonly EventosDoEspacoAposConfirmar $eventos,
        private readonly LoggerInterface $logger,
        private readonly FolgaDaAmpliacao $ampliacao,
    ) {
    }

    public function gravar(ChaveDeArquivo|NovoArquivo $destino, FonteDeConteudo $fonte): ArquivoArmazenado
    {
        $tenantId = $destino instanceof NovoArquivo && self::cobrado($destino) ? $destino->escopo->tenantIdOuNull() : null;
        if ($tenantId === null) {
            return $this->interno->gravar($destino, $fonte);
        }

        $antes = $this->retrato($tenantId);
        if (!$this->cabe($antes, 0)) {
            $this->recusar($antes);
        }

        $armazenado = $this->interno->gravar($destino, $fonte);
        $this->desfazerSeNaoCoube($antes, $armazenado);
        $this->usados[$tenantId] += $armazenado->tamanhoBytes;
        $this->avisarSeSubiu($antes, $this->retrato($tenantId));

        return $armazenado;
    }

    public function abrir(ChaveDeArquivo $chave): mixed
    {
        return $this->interno->abrir($chave);
    }

    public function ler(ChaveDeArquivo $chave): string
    {
        return $this->interno->ler($chave);
    }

    public function existe(ChaveDeArquivo $chave): bool
    {
        return $this->interno->existe($chave);
    }

    public function excluir(ChaveDeArquivo $chave): void
    {
        $this->interno->excluir($chave);
    }

    public function metadados(ChaveDeArquivo $chave): ?MetadadosDeArquivo
    {
        return $this->interno->metadados($chave);
    }

    public function reset(): void
    {
        $this->usados = [];
        $this->folgas = [];
        $this->ampliacaoPedida = [];
    }

    /** Só bloqueia o que a soma do espaço cobra (§5 da spec): não se recusa o que não se conta. */
    private static function cobrado(NovoArquivo $destino): bool
    {
        return \in_array($destino->categoria, self::CATEGORIAS_COBRADAS, true);
    }

    private function retrato(int $tenantId): RetratoDoEspaco
    {
        $this->usados[$tenantId] ??= $this->consumo->bytesDoEscritorio($tenantId);

        return $this->situacao->retratar($tenantId, $this->usados[$tenantId]);
    }

    /** Passou do limite com este arquivo: ele sai já (nenhum registro aponta para ele ainda) e o envio é recusado. */
    private function desfazerSeNaoCoube(RetratoDoEspaco $antes, ArquivoArmazenado $armazenado): void
    {
        if ($this->cabe($antes, $armazenado->tamanhoBytes)) {
            return;
        }

        (new RemocaoAposTransacao($this->interno, $this->logger))
            ->remover([$armazenado->chave], 'arquivo recusado por falta de espaço');

        $this->recusar($antes);
    }

    /**
     * Cabe no limite, ou cabe com a folga de uma dobra da ampliação automática (e então a ampliação é pedida para
     * depois do commit). A folga só é lida do banco quando o envio não cabe.
     */
    private function cabe(RetratoDoEspaco $retrato, int $bytes): bool
    {
        if ($retrato->cabe($bytes)) {
            return true;
        }
        $tenantId = $retrato->tenantId;
        $this->folgas[$tenantId] ??= $this->ampliacao->folgaDaAmpliacao($tenantId);
        if ($this->folgas[$tenantId] === 0 || !$retrato->cabeComFolga($bytes, $this->folgas[$tenantId])) {
            return false;
        }
        if (!isset($this->ampliacaoPedida[$tenantId])) {
            $this->ampliacaoPedida[$tenantId] = true;
            $this->eventos->adiar(new EspacoPassouDoContratado($tenantId));
        }

        return true;
    }

    /** Avisa a recusa na hora (é o fato, não depende do trabalho) e recusa. */
    private function recusar(RetratoDoEspaco $retrato): never
    {
        $this->eventos->agora(new EnvioRecusadoPorEspaco($retrato->tenantId, $retrato));

        throw new EspacoEsgotado($retrato);
    }

    /** Os eventos saem depois do commit de quem gravou (`EventosDoEspacoAposConfirmar`). */
    private function avisarSeSubiu(RetratoDoEspaco $antes, RetratoDoEspaco $depois): void
    {
        if ($antes->percentual !== null && $depois->percentual !== null && $depois->percentual > $antes->percentual) {
            $this->eventos->adiar(new UsoDoEspacoSubiu($depois->tenantId, $antes->percentual, $depois->percentual, $depois));
        }
        if ($depois->faixa->ordem() > $antes->faixa->ordem()) {
            $this->eventos->adiar(new EspacoAtingiuFaixa($depois->tenantId, $depois->faixa, $antes->faixa, $depois, $antes->percentual));
        }
    }
}
