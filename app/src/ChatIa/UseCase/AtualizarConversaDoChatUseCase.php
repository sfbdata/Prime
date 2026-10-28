<?php

declare(strict_types=1);

namespace App\ChatIa\UseCase;

use App\ChatIa\DTO\AtualizarConversaInput;
use App\ChatIa\Service\AcessoAoChat;
use App\ChatIa\Service\TransacaoDoChat;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Renomear, fixar e trocar o modelo da conversa (`PATCH`): só o que veio no input muda. Renomear à mão vale sobre o
 * título automático. Conversa que não é de quem pede: 404.
 *
 * @throws \App\ChatIa\Exception\SemAcessoAoChatException
 * @throws \App\ChatIa\Exception\ConversaDeChatNaoEncontradaException
 */
final class AtualizarConversaDoChatUseCase
{
    public function __construct(
        private readonly AcessoAoChat $acesso,
        private readonly TransacaoDoChat $transacao,
    ) {
    }

    public function executar(User $eu, ?Tenant $tenant, int $conversaId, AtualizarConversaInput $input): void
    {
        $tenant = $this->acesso->exigir($eu, $tenant);
        $conversa = $this->acesso->conversaDoDono($conversaId, $eu, $tenant);

        $this->transacao->executar(static function () use ($conversa, $input): void {
            if ($input->titulo !== null) {
                $conversa->renomear($input->titulo);
            }
            if ($input->fixada !== null) {
                $conversa->fixar($input->fixada);
            }
            if ($input->modelo !== null) {
                $conversa->trocarModelo($input->modelo);
            }
        });
    }
}
