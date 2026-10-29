<?php

declare(strict_types=1);

namespace App\ChatIa\UseCase;

use App\ChatIa\DTO\AnexoOutput;
use App\ChatIa\DTO\ConversaOutput;
use App\ChatIa\DTO\MensagemOutput;
use App\ChatIa\Entity\MensagemDeChat;
use App\ChatIa\Repository\AnexoDeChatRepository;
use App\ChatIa\Repository\MensagemDeChatRepository;
use App\ChatIa\Repository\PropostaDeCadastroRepository;
use App\ChatIa\Service\AcessoAoChat;
use App\ChatIa\Service\MontadorDoCartaoDaProposta;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Psr\Clock\ClockInterface;

/**
 * A conversa aberta, com todas as falas, os anexos e as propostas de cadastro de cada uma (no estado atual). Conversa que não é de quem pede: 404.
 *
 * @throws \App\ChatIa\Exception\SemAcessoAoChatException
 * @throws \App\ChatIa\Exception\ConversaDeChatNaoEncontradaException
 */
final class VerConversaDoChatUseCase
{
    public function __construct(
        private readonly AcessoAoChat $acesso,
        private readonly MensagemDeChatRepository $mensagens,
        private readonly AnexoDeChatRepository $anexos,
        private readonly PropostaDeCadastroRepository $propostas,
        private readonly MontadorDoCartaoDaProposta $cartao,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(User $eu, ?Tenant $tenant, int $conversaId): ConversaOutput
    {
        $tenant = $this->acesso->exigir($eu, $tenant);
        $conversa = $this->acesso->conversaDoDono($conversaId, $eu, $tenant);

        $anexosPorMensagem = [];
        foreach ($this->anexos->daConversa($conversa, $tenant) as $anexo) {
            if ($anexo->getMensagem() !== null) {
                $anexosPorMensagem[(int) $anexo->getMensagem()->getId()][] = AnexoOutput::de($anexo);
            }
        }

        $agora = \DateTimeImmutable::createFromInterface($this->relogio->now());
        $this->propostas->expirarVencidas($tenant, $agora);   // abrir a conversa também apaga os dados pessoais das vencidas
        $propostasPorMensagem = [];
        foreach ($this->propostas->daConversa($conversa, $tenant) as $proposta) {
            if ($proposta->getMensagem() !== null) {
                $propostasPorMensagem[(int) $proposta->getMensagem()->getId()][] = $this->cartao->montar($proposta, $agora);
            }
        }

        return new ConversaOutput(
            (int) $conversa->getId(),
            $conversa->getTitulo(),
            $conversa->getModelo()->value,
            $conversa->estaFixada(),
            array_map(
                static fn (MensagemDeChat $m): MensagemOutput => MensagemOutput::de($m, $anexosPorMensagem[(int) $m->getId()] ?? [], $propostasPorMensagem[(int) $m->getId()] ?? []),
                $this->mensagens->daConversa($conversa, $tenant),
            ),
        );
    }
}
