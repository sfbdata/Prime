<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Repository\TarefaMensagemRepository;
use App\Repository\UserTenantRepository;
use App\Service\NotificacaoService;
use App\Tarefa\DTO\DespachoRegistradoOutput;
use App\Tarefa\DTO\DespacharNaMetaInput;
use App\Tarefa\Enum\AcaoDoDespacho;
use App\Tarefa\Exception\DespachoRecusadoException;
use App\Tarefa\Service\SorteioDeCodigoDoDespacho;
use App\Tarefa\Service\TextoDoDespacho;

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
 * Tudo numa transação: o despacho é gravado, e o código é gravado depois, com até 3 sorteios contra a colisão no
 * índice único. Sem código livre, nada fica (nem a troca de status); quem enviou o anexo apaga o arquivo.
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
    ) {
    }

    public function executar(Tarefa $meta, User $autor, Tenant $escritorio, DespacharNaMetaInput $input): DespachoRegistradoOutput
    {
        if ($meta->getTenant()?->getId() !== $escritorio->getId()) {
            throw DespachoRecusadoException::foraDoEscritorio();
        }
        $temConteudo = $input->texto !== '' || $input->arquivoAnexo !== null;
        if (!$temConteudo && $input->acao === '') {
            throw DespachoRecusadoException::semConteudo();
        }
        self::exigirStatusDaAcao($meta, $input->acao);

        /** @var array{0: ?TarefaMensagem, 1: bool, 2: bool} $feito */
        $feito = $this->mensagens->emTransacao(function () use ($meta, $autor, $escritorio, $input, $temConteudo): array {
            [$enviada, $devolvida] = $this->mudarStatus($meta, $input->acao);
            $despacho = $temConteudo || $enviada ? $this->registrar($meta, $autor, $escritorio, $input, self::acao($enviada, $devolvida)) : null;
            $this->mensagens->confirmar();
            if ($despacho !== null) {
                $this->gravarCodigo($despacho);
            }

            return [$despacho, $enviada, $devolvida];
        });
        $this->avisar($meta, $feito[1], $feito[2]);

        return new DespachoRegistradoOutput($feito[0]?->getCodigo(), $feito[1], $feito[2]);
    }

    private function registrar(Tarefa $meta, User $autor, Tenant $escritorio, DespacharNaMetaInput $input, AcaoDoDespacho $acao): TarefaMensagem
    {
        $despacho = new TarefaMensagem();
        $despacho->setUsuario($autor);
        $despacho->setTenant($escritorio);
        $despacho->setMensagem(match (true) {
            $input->texto !== ''           => $this->textoDoDespacho->paraHtml($input->texto),
            $acao === AcaoDoDespacho::Envio => $this->textoDoDespacho->paraHtml(self::ENVIO_SEM_TEXTO),
            default                        => '[Arquivo anexado]',
        });
        $despacho->setArquivoAnexo($input->arquivoAnexo);
        $despacho->setAcao($acao);
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

        return $despacho;
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
