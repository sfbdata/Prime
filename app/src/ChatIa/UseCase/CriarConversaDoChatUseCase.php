<?php

declare(strict_types=1);

namespace App\ChatIa\UseCase;

use App\ChatIa\Entity\ConversaDeChat;
use App\ChatIa\Enum\OrigemDaConversa;
use App\ChatIa\Repository\ConversaDeChatRepository;
use App\ChatIa\Service\AcessoAoChat;
use App\ChatIa\Service\TransacaoDoChat;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Enum\ModeloDeIa;
use Psr\Clock\ClockInterface;

/**
 * Cria uma conversa vazia para quem pede, no escritório da sessão, com o modelo padrão (Haiku). Devolve o id.
 * Teto: quem já tem {@see MAXIMO_DE_VAZIAS} conversas SEM mensagem reaproveita a vazia mais recente em vez de criar outra
 * (segura laço e clique repetido sem custo para quem usa normalmente).
 *
 * @throws \App\ChatIa\Exception\SemAcessoAoChatException
 */
final class CriarConversaDoChatUseCase
{
    public const MAXIMO_DE_VAZIAS = 20;

    public function __construct(
        private readonly AcessoAoChat $acesso,
        private readonly ConversaDeChatRepository $conversas,
        private readonly TransacaoDoChat $transacao,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function executar(User $eu, ?Tenant $tenant, OrigemDaConversa $origem = OrigemDaConversa::Pagina): int
    {
        $tenant = $this->acesso->exigir($eu, $tenant);
        if ($this->conversas->contarVaziasDoDono($tenant, $eu) >= self::MAXIMO_DE_VAZIAS) {
            $vazia = $this->conversas->vaziaMaisRecenteDoDono($tenant, $eu);
            if ($vazia !== null) {
                return (int) $vazia->getId();
            }
        }
        $conversa = new ConversaDeChat($tenant, $eu, ModeloDeIa::PADRAO, \DateTimeImmutable::createFromInterface($this->relogio->now()));
        $conversa->nascerEm($origem);

        $this->transacao->executar(function () use ($conversa): void {
            $this->conversas->salvar($conversa);
        });

        return (int) $conversa->getId();
    }
}
