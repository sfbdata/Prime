<?php

declare(strict_types=1);

namespace App\ChatIa\UseCase;

use App\ChatIa\Entity\ConversaDeChat;
use App\ChatIa\Repository\ConversaDeChatRepository;
use App\ChatIa\Service\AcessoAoChat;
use App\ChatIa\Service\TransacaoDoChat;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Enum\ModeloDeIa;
use Psr\Clock\ClockInterface;

/**
 * Cria uma conversa vazia para quem pede, no escritório da sessão, com o modelo padrão (Haiku). Devolve o id.
 *
 * @throws \App\ChatIa\Exception\SemAcessoAoChatException
 */
final class CriarConversaDoChatUseCase
{
    public function __construct(
        private readonly AcessoAoChat $acesso,
        private readonly ConversaDeChatRepository $conversas,
        private readonly TransacaoDoChat $transacao,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(User $eu, ?Tenant $tenant): int
    {
        $tenant = $this->acesso->exigir($eu, $tenant);
        $conversa = new ConversaDeChat($tenant, $eu, ModeloDeIa::PADRAO, \DateTimeImmutable::createFromInterface($this->relogio->now()));

        $this->transacao->executar(function () use ($conversa): void {
            $this->conversas->salvar($conversa);
        });

        return (int) $conversa->getId();
    }
}
