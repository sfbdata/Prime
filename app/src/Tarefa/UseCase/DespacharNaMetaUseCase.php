<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Auth\User;
use App\Entity\Notificacao;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Service\ResolucaoDeMencoesDoRegistro;
use App\Repository\TarefaMensagemRepository;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Tarefa\DTO\DespachoRegistradoOutput;
use App\Tarefa\DTO\DespacharNaMetaInput;
use App\Tarefa\Enum\AcaoDoDespacho;
use App\Tarefa\Exception\DespachoRecusadoException;
use App\Tarefa\Service\AnexoDoDespacho;
use App\Tarefa\Service\CodigoDoRegistro;
use App\Tarefa\Service\TextoDoDespacho;
use App\Tarefa\Service\TransacaoDoDespacho;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Despachar na meta (desenho 04B de 22/10, L57-66 e L145; spec `minhas-metas-esteira-2210.md`, Fase 2a).
 *
 * Quem tem acesso à meta escreve um despacho (texto ou anexo) e, junto, pode "Enviar para revisão" (pendente para em
 * revisão, avisa o criador). O despacho do criador na meta em revisão a devolve para pendente e avisa os responsáveis
 * (decisão do dono, 22/10); o "Devolver para X" do responsável é o modal da Fase 3 ({@see DevolverMetaUseCase}). Cada despacho gravado recebe o código do registro (D1, C13), sorteado e único no escritório,
 * com o nome e o cargo de quem despachou congelados no registro.
 *
 * A mensagem guarda a ação que de fato aconteceu (despacho, envio ou devolução, L127). Natureza e prazo de
 * cumprimento valem para despacho e devolução. O envio para revisão do desenho (L146) não leva nenhum dos dois: o
 * prazo padrão, sempre preenchido na tela, viraria um "Cumprir até" na mensagem de quem entrega o trabalho. Enviar
 * para revisão sem texto grava "Meta enviada para revisão do autor." (L146), e o aviso ao autor é o de sempre.
 *
 * "Enviar para revisão" só vale na meta pendente; fora dela, recusa sem gravar. Na
 * meta concluída, o despacho entra como registro, sem mudar o status.
 *
 * @menção (C3): o texto chega com os tokens `@[Nome](user:ID)` do `pasta-mencoes.js`; o mesmo mecanismo do Registro
 * da pasta confere cada id contra os colegas ativos do escritório, troca o rótulo pelo nome do banco e avisa na
 * Central quem vê a pasta da meta.
 *
 * Anexo (C4): entra nos documentos da pasta pela regra de chaves ({@see AnexoDoDespacho}); a mensagem guarda só a
 * referência ao documento. Tipo ou tamanho recusado: nada é gravado.
 *
 * Tudo numa transação ({@see TransacaoDoDespacho}, a `TransacaoComArquivoNovo` da casa): o anexo e o despacho são
 * gravados, e o código é gravado depois pelo {@see CodigoDoRegistro} (até 3 sorteios contra a colisão no índice único). Sem código livre, nada
 * fica (nem a troca de status). O arquivo do anexo só sai do storage quando o COMMIT comprovadamente não aconteceu;
 * COMMIT de resultado incerto preserva o arquivo (o documento pode ter ficado apontando para ele).
 */
final class DespacharNaMetaUseCase
{
    /** Texto do "Enviar para revisão" sem texto (desenho 04B L146). */
    public const ENVIO_SEM_TEXTO = 'Meta enviada para revisão do autor.';

    public function __construct(
        private readonly TarefaMensagemRepository $mensagens,
        private readonly TextoDoDespacho $textoDoDespacho,
        private readonly CodigoDoRegistro $codigo,
        private readonly NotificacaoService $notificacoes,
        private readonly UserTenantRepository $vinculos,
        private readonly ResolucaoDeMencoesDoRegistro $mencoes,
        private readonly AnexoDoDespacho $anexos,
        private readonly TransacaoDoDespacho $transacao,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function executar(Tarefa $meta, User $autor, Tenant $escritorio, DespacharNaMetaInput $input, ?UploadedFile $anexo = null): DespachoRegistradoOutput
    {
        if ($meta->getTenant()?->getId() !== $escritorio->getId()) {
            throw DespachoRecusadoException::foraDoEscritorio();
        }
        if ($input->texto === '' && $anexo === null && $input->acao === '') {
            throw DespachoRecusadoException::semConteudo();
        }
        self::exigirStatusDaAcao($meta, $input->acao);

        /** @var \ArrayObject<int, ChaveDeArquivo> $gravados chaves do anexo, preenchidas assim que o arquivo é gravado */
        $gravados = new \ArrayObject();
        /** @var array{0: ?TarefaMensagem, 1: bool} $feito */
        $feito = $this->transacao->executar(
            fn (): array => $this->gravar($meta, $autor, $escritorio, $input, $anexo, $gravados),
            static fn (): array => array_values($gravados->getArrayCopy()),
        );
        if ($feito[1]) {
            $this->notificacoes->notificarTarefaEmRevisao($meta);
        }

        return new DespachoRegistradoOutput($feito[0]?->getCodigo(), $feito[1]);
    }

    /**
     * Os passos dentro da transação. A chave do anexo vai para `$gravados` assim que o arquivo é gravado (mesmo que o
     * upload falhe depois), para a transação decidir o destino dele se o COMMIT não acontecer.
     *
     * @param \ArrayObject<int, ChaveDeArquivo> $gravados
     *
     * @return array{0: ?TarefaMensagem, 1: bool} o despacho e se foi enviada para revisão
     */
    private function gravar(Tarefa $meta, User $autor, Tenant $escritorio, DespacharNaMetaInput $input, ?UploadedFile $anexo, \ArrayObject $gravados): array
    {
        $documento = $anexo === null ? null : $this->juntar($meta, $anexo, $autor, $escritorio, $gravados);
        $acao = $this->mudarStatus($meta, $autor, $input, $documento !== null);
        $despacho = null;
        $mencionados = [];
        if ($input->texto !== '' || $documento !== null || $acao === AcaoDoDespacho::Envio) {
            [$despacho, $mencionados] = $this->registrar($meta, $autor, $escritorio, $input, $acao, $documento);
        }
        $this->mensagens->confirmar();
        if ($despacho !== null) {
            $this->codigo->gravar($despacho);
        }
        if ($despacho !== null && $mencionados !== []) {
            $this->mencoes->notificarNoDespacho($mencionados, $despacho, $meta, $autor, $escritorio);
            $this->mensagens->confirmar();
        }
        if ($acao === AcaoDoDespacho::Devolucao) {
            $this->avisarDevolucao($meta, $autor, $escritorio);
        }

        return [$despacho, $acao === AcaoDoDespacho::Envio];
    }

    /**
     * O ciclo de vida junto do despacho: "Enviar para revisão" leva a pendente para em revisão; o despacho do criador
     * (texto ou anexo) na meta em revisão a devolve para pendente (decisão do dono, 22/10). O de outra pessoa só
     * registra.
     */
    private function mudarStatus(Tarefa $meta, User $autor, DespacharNaMetaInput $input, bool $temAnexo): AcaoDoDespacho
    {
        if ($input->acao === 'enviar' && $meta->getStatus() === Tarefa::STATUS_PENDENTE) {
            $meta->setStatus(Tarefa::STATUS_EM_REVISAO);

            return AcaoDoDespacho::Envio;
        }
        $doCriador = $meta->getCriadoPor() !== null && $meta->getCriadoPor()->getId() === $autor->getId();
        if ($doCriador && $meta->getStatus() === Tarefa::STATUS_EM_REVISAO && ($input->texto !== '' || $temAnexo)) {
            $meta->setStatus(Tarefa::STATUS_PENDENTE);

            return AcaoDoDespacho::Devolucao;
        }

        return AcaoDoDespacho::Despacho;
    }

    /** Avisa os responsáveis da devolução (tipo "tarefa pendente", como o Devolver da Fase 3), nunca o próprio autor. */
    private function avisarDevolucao(Tarefa $meta, User $autor, Tenant $escritorio): void
    {
        $titulo = sprintf('Meta devolvida · Pasta %s', $meta->getPasta()->getNup() ?? '#' . $meta->getPasta()->getId());
        $texto = sprintf('%s devolveu "%s".', mb_convert_case(trim((string) ($autor->getFullName() ?? $autor->getEmail())), MB_CASE_TITLE), $meta->getTitulo());
        foreach ($meta->getResponsaveis() as $pessoa) {
            if ($pessoa->getId() === $autor->getId() || !$this->vinculos->existeVinculoAtivo($pessoa, $escritorio) || !$this->notificacoes->veAPastaDaMeta($meta, $pessoa)) {
                continue;
            }
            $this->notificacoes->criar($pessoa, $escritorio, Notificacao::TIPO_TAREFA_PENDENTE, $titulo, $texto, $meta)
                ->setUrl($this->urls->generate('tarefa_show', ['id' => $meta->getId()]));
        }
        $this->mensagens->confirmar();
    }

    /** @param \ArrayObject<int, ChaveDeArquivo> $gravados */
    private function juntar(Tarefa $meta, UploadedFile $anexo, User $autor, Tenant $escritorio, \ArrayObject $gravados): PastaDocumento
    {
        try {
            return $this->anexos->juntar($meta, $anexo, $autor, $escritorio, static function (ChaveDeArquivo $chave) use ($gravados): void {
                $gravados->append($chave);
            });
        } catch (\InvalidArgumentException $e) {
            throw DespachoRecusadoException::anexoRecusado($e->getMessage());
        }
    }

    /** @return array{0: TarefaMensagem, 1: list<User>} o despacho e os mencionados válidos (C3) */
    private function registrar(Tarefa $meta, User $autor, Tenant $escritorio, DespacharNaMetaInput $input, AcaoDoDespacho $acao, ?PastaDocumento $documento): array
    {
        [$texto, $mencionados] = $this->mencoes->resolver(match (true) {
            $input->texto !== ''           => $this->textoDoDespacho->paraHtml($input->texto),
            $acao === AcaoDoDespacho::Envio => $this->textoDoDespacho->paraHtml(self::ENVIO_SEM_TEXTO),
            default                        => '[Arquivo anexado]',
        }, $escritorio);
        $despacho = (new TarefaMensagem())->setUsuario($autor)->setTenant($escritorio)->setMensagem($texto)->setAcao($acao);
        if ($documento !== null) {
            $despacho->juntarDocumentoDaPasta((int) $documento->getId());
        }
        if ($acao !== AcaoDoDespacho::Envio) {
            $despacho->setNatureza($input->naturezaEscolhida());
            $despacho->setPrazoCumprimento($input->prazoComoData());
        }
        $despacho->registrarAutoria(
            (string) ($autor->getFullName() ?? $autor->getEmail()),
            $this->vinculos->findPorUserETenant($autor, $escritorio)?->getCargo()?->getNome(),
        );
        $meta->addMensagem($despacho);
        $this->mensagens->salvar($despacho);

        return [$despacho, $mencionados];
    }

    /** Enviar só da pendente; fora dela, recusa antes de gravar qualquer coisa. */
    private static function exigirStatusDaAcao(Tarefa $meta, string $acao): void
    {
        if ($acao === 'enviar' && $meta->getStatus() !== Tarefa::STATUS_PENDENTE) {
            throw DespachoRecusadoException::envioForaDePendente();
        }
    }
}
