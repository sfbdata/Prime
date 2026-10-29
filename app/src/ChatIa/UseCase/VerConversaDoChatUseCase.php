<?php

declare(strict_types=1);

namespace App\ChatIa\UseCase;

use App\ChatIa\DTO\AnexoOutput;
use App\ChatIa\DTO\ConversaOutput;
use App\ChatIa\DTO\MensagemOutput;
use App\ChatIa\Entity\MensagemDeChat;
use App\ChatIa\Repository\AnexoDeChatRepository;
use App\ChatIa\Repository\MensagemDeChatRepository;
use App\ChatIa\Service\AcessoAoChat;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * A conversa aberta, com todas as falas e os anexos de cada uma. Conversa que não é de quem pede: 404.
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

        return new ConversaOutput(
            (int) $conversa->getId(),
            $conversa->getTitulo(),
            $conversa->getModelo()->value,
            $conversa->estaFixada(),
            array_map(
                static fn (MensagemDeChat $m): MensagemOutput => MensagemOutput::de($m, $anexosPorMensagem[(int) $m->getId()] ?? []),
                $this->mensagens->daConversa($conversa, $tenant),
            ),
        );
    }
}
