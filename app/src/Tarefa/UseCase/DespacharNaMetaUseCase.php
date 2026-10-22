<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tarefa\TarefaMensagem;
use App\Entity\Tenant\Tenant;
use App\Repository\TarefaMensagemRepository;
use App\Service\NotificacaoService;
use App\Tarefa\DTO\DespachoRegistradoOutput;
use App\Tarefa\DTO\DespacharNaMetaInput;
use App\Tarefa\Exception\DespachoRecusadoException;
use App\Tarefa\Service\SorteioDeCodigoDoDespacho;
use App\Tarefa\Service\TextoDoDespacho;

/**
 * Despachar na meta (desenho 04B de 22/10, L57-66 e L145; spec `minhas-metas-esteira-2210.md`, Fase 2a).
 *
 * Quem tem acesso à meta escreve um despacho (texto ou anexo) e, junto, pode mudar o status pelo ciclo de vida:
 * "Enviar para revisão" (pendente para em revisão, avisa o criador) ou "Devolver" (em revisão para pendente, avisa
 * os responsáveis). Cada despacho gravado recebe o código do registro (D1, C13), sorteado e único no escritório.
 *
 * Natureza e prazo de cumprimento valem para Despachar e Devolver. O "Enviar para revisão" do desenho (L146) não
 * leva nenhum dos dois: o prazo padrão, sempre preenchido na tela, viraria um "Cumprir até" na mensagem de quem
 * entrega o trabalho.
 */
final class DespacharNaMetaUseCase
{
    private const TENTATIVAS_DO_SORTEIO = 10;

    public function __construct(
        private readonly TarefaMensagemRepository $mensagens,
        private readonly TextoDoDespacho $textoDoDespacho,
        private readonly SorteioDeCodigoDoDespacho $sorteio,
        private readonly NotificacaoService $notificacoes,
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

        $despacho = $temConteudo ? $this->registrar($meta, $autor, $escritorio, $input) : null;
        [$enviada, $devolvida] = $this->mudarStatus($meta, $input->acao);
        $this->mensagens->confirmar();
        $this->avisar($meta, $enviada, $devolvida);

        return new DespachoRegistradoOutput($despacho?->getCodigo(), $enviada, $devolvida);
    }

    private function registrar(Tarefa $meta, User $autor, Tenant $escritorio, DespacharNaMetaInput $input): TarefaMensagem
    {
        $despacho = new TarefaMensagem();
        $despacho->setUsuario($autor);
        $despacho->setTenant($escritorio);
        $despacho->setMensagem($input->texto !== '' ? $this->textoDoDespacho->paraHtml($input->texto) : '[Arquivo anexado]');
        $despacho->setArquivoAnexo($input->arquivoAnexo);
        $despacho->registrarCodigo($this->codigoLivre($escritorio));
        if ($input->acao !== 'enviar') {
            $despacho->setNatureza($input->naturezaEscolhida());
            $despacho->setPrazoCumprimento($input->prazoComoData());
        }
        $meta->addMensagem($despacho);
        $this->mensagens->salvar($despacho);

        return $despacho;
    }

    /** Sorteia até achar um código que o escritório ainda não usou (C13). */
    private function codigoLivre(Tenant $escritorio): string
    {
        for ($tentativa = 0; $tentativa < self::TENTATIVAS_DO_SORTEIO; ++$tentativa) {
            $codigo = $this->sorteio->sortear();
            if (!$this->mensagens->codigoJaUsado($escritorio, $codigo)) {
                return $codigo;
            }
        }

        throw DespachoRecusadoException::semCodigoLivre();
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
