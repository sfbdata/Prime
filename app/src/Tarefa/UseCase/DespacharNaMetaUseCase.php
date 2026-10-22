<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Service\ResolucaoDeMencoesDoRegistro;
use App\Repository\TarefaMensagemRepository;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use App\Tarefa\DTO\DespachoRegistradoOutput;
use App\Tarefa\DTO\DespacharNaMetaInput;
use App\Tarefa\Enum\AcaoDoDespacho;
use App\Tarefa\Exception\DespachoRecusadoException;
use App\Tarefa\Service\AnexoDoDespacho;
use App\Tarefa\Service\SorteioDeCodigoDoDespacho;
use App\Tarefa\Service\TextoDoDespacho;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Despachar na meta (desenho 04B de 22/10, L57-66 e L145; spec `minhas-metas-esteira-2210.md`, Fase 2a).
 *
 * Quem tem acesso à meta escreve um despacho (texto ou anexo) e, junto, pode mudar o status pelo ciclo de vida:
 * "Enviar para revisão" (pendente para em revisão, avisa o criador) ou "Devolver" (em revisão para pendente, avisa
 * os responsáveis). Cada despacho gravado recebe o código do registro (D1, C13), sorteado e único no escritório,
 * com o nome e o cargo de quem despachou congelados no registro.
 *
 * A mensagem guarda a ação que de fato aconteceu (despacho, envio ou devolução, L127). Natureza e prazo de
 * cumprimento valem para despacho e devolução. O envio para revisão do desenho (L146) não leva nenhum dos dois: o
 * prazo padrão, sempre preenchido na tela, viraria um "Cumprir até" na mensagem de quem entrega o trabalho. Enviar
 * para revisão sem texto grava "Meta enviada para revisão do autor." (L146), e o aviso ao autor é o de sempre.
 *
 * "Enviar para revisão" só vale na meta pendente e "Devolver" só na em revisão; fora disso, recusa sem gravar. Na
 * meta concluída, o despacho entra como registro, sem mudar o status.
 *
 * @menção (C3): o texto chega com os tokens `@[Nome](user:ID)` do `pasta-mencoes.js`; o mesmo mecanismo do Registro
 * da pasta confere cada id contra os colegas ativos do escritório, troca o rótulo pelo nome do banco e avisa na
 * Central quem vê a pasta da meta.
 *
 * Anexo (C4): entra nos documentos da pasta pela regra de chaves ({@see AnexoDoDespacho}); a mensagem guarda só a
 * referência ao documento. Tipo ou tamanho recusado: nada é gravado.
 *
 * Tudo numa transação: o anexo e o despacho são gravados, e o código é gravado depois, com até 3 sorteios contra a
 * colisão no índice único. Sem código livre, nada fica (nem a troca de status) e o arquivo do anexo sai do storage.
 */
final class DespacharNaMetaUseCase
{
    /** Sorteios contra a colisão no índice único do código (C13). */
    private const TENTATIVAS_DO_CODIGO = 3;

    /** Texto do "Enviar para revisão" sem texto (desenho 04B L146). */
    public const ENVIO_SEM_TEXTO = 'Meta enviada para revisão do autor.';

    public function __construct(
        private readonly TarefaMensagemRepository $mensagens,
        private readonly TextoDoDespacho $textoDoDespacho,
        private readonly SorteioDeCodigoDoDespacho $sorteio,
        private readonly NotificacaoService $notificacoes,
        private readonly UserTenantRepository $vinculos,
        private readonly ResolucaoDeMencoesDoRegistro $mencoes,
        private readonly AnexoDoDespacho $anexos,
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

        $juntado = new \ArrayObject();
        try {
            /** @var array{0: ?TarefaMensagem, 1: bool, 2: bool} $feito */
            $feito = $this->mensagens->emTransacao(fn (): array => $this->gravar($meta, $autor, $escritorio, $input, $anexo, $juntado));
        } catch (\Throwable $e) {
            // A transação voltou: o documento não ficou, e o arquivo dele não pode ficar órfão no storage.
            foreach ($juntado as $documento) {
                $this->anexos->descartar($documento);
            }
            throw $e;
        }
        $this->avisar($meta, $feito[1], $feito[2]);

        return new DespachoRegistradoOutput($feito[0]?->getCodigo(), $feito[1], $feito[2]);
    }

    /**
     * Os passos dentro da transação. O documento juntado vai para `$juntado` assim que existe, para quem chama poder
     * descartar o arquivo se a transação voltar.
     *
     * @param \ArrayObject<int, PastaDocumento> $juntado
     *
     * @return array{0: ?TarefaMensagem, 1: bool, 2: bool}
     */
    private function gravar(Tarefa $meta, User $autor, Tenant $escritorio, DespacharNaMetaInput $input, ?UploadedFile $anexo, \ArrayObject $juntado): array
    {
        $documento = $anexo === null ? null : $this->juntar($meta, $anexo, $autor, $escritorio);
        if ($documento !== null) {
            $juntado->append($documento);
        }
        [$enviada, $devolvida] = $this->mudarStatus($meta, $input->acao);
        $despacho = null;
        $mencionados = [];
        if ($input->texto !== '' || $documento !== null || $enviada) {
            [$despacho, $mencionados] = $this->registrar($meta, $autor, $escritorio, $input, self::acao($enviada, $devolvida), $documento);
        }
        $this->mensagens->confirmar();
        if ($despacho !== null) {
            $this->gravarCodigo($despacho);
        }
        if ($despacho !== null && $mencionados !== []) {
            $this->mencoes->notificarNoDespacho($mencionados, $despacho, $meta, $autor, $escritorio);
            $this->mensagens->confirmar();
        }

        return [$despacho, $enviada, $devolvida];
    }

    private function juntar(Tarefa $meta, UploadedFile $anexo, User $autor, Tenant $escritorio): PastaDocumento
    {
        try {
            return $this->anexos->juntar($meta, $anexo, $autor, $escritorio);
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

    /** Sorteia e grava; colidiu no índice único, sorteia de novo (C13). Sem código livre, a transação inteira volta. */
    private function gravarCodigo(TarefaMensagem $despacho): void
    {
        for ($tentativa = 0; $tentativa < self::TENTATIVAS_DO_CODIGO; ++$tentativa) {
            if ($this->mensagens->gravarCodigoSeLivre($despacho, $this->sorteio->sortear())) {
                return;
            }
        }

        throw DespachoRecusadoException::semCodigoLivre();
    }

    /** Enviar só da pendente e devolver só da em revisão; fora disso, recusa antes de gravar qualquer coisa. */
    private static function exigirStatusDaAcao(Tarefa $meta, string $acao): void
    {
        if ($acao === 'enviar' && $meta->getStatus() !== Tarefa::STATUS_PENDENTE) {
            throw DespachoRecusadoException::envioForaDePendente();
        }
        if ($acao === 'devolver' && $meta->getStatus() !== Tarefa::STATUS_EM_REVISAO) {
            throw DespachoRecusadoException::devolucaoForaDeRevisao();
        }
    }

    private static function acao(bool $enviada, bool $devolvida): AcaoDoDespacho
    {
        return match (true) {
            $enviada   => AcaoDoDespacho::Envio,
            $devolvida => AcaoDoDespacho::Devolucao,
            default    => AcaoDoDespacho::Despacho,
        };
    }

    /** @return array{bool, bool} enviada para revisão, devolvida */
    private function mudarStatus(Tarefa $meta, string $acao): array
    {
        if ($acao === 'enviar' && $meta->getStatus() === Tarefa::STATUS_PENDENTE) {
            $meta->setStatus(Tarefa::STATUS_EM_REVISAO);

            return [true, false];
        }
        if ($acao === 'devolver' && $meta->getStatus() === Tarefa::STATUS_EM_REVISAO) {
            $meta->setStatus(Tarefa::STATUS_PENDENTE);

            return [false, true];
        }

        return [false, false];
    }

    private function avisar(Tarefa $meta, bool $enviada, bool $devolvida): void
    {
        if ($enviada) {
            $this->notificacoes->notificarTarefaEmRevisao($meta);
        }
        if ($devolvida) {
            $this->notificacoes->notificarTarefaPendente($meta);
        }
    }
}
