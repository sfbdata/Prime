<?php

declare(strict_types=1);

namespace App\ChatIa\Service;

use App\ChatIa\Exception\TetoDiarioDoChatException;
use App\ChatIa\Repository\MensagemDeChatRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Psr\Clock\ClockInterface;

/**
 * Os tetos de custo do Chat I.A (spec chat-pagina-inteira, decisão 10), vindos de `services.yaml`
 * (`CHAT_IA_TETO_TOKENS_RESPOSTA` e `CHAT_IA_TETO_TOKENS_DIA`):
 * - por resposta: o `max_tokens` do pedido;
 * - por dia e por pessoa: a soma de tokens (entrada + saída) das mensagens do dia nas conversas dela. Estourou = recusa.
 */
final class TetoDeUsoDoChat
{
    public function __construct(
        private readonly MensagemDeChatRepository $mensagens,
        private readonly ClockInterface $relogio,
        private readonly int $tetoPorResposta,
        private readonly int $tetoPorDia,
    ) {
    }

    public function porResposta(): int
    {
        return max(16, $this->tetoPorResposta);
    }

    /** @throws TetoDiarioDoChatException */
    public function conferirODia(User $pessoa, Tenant $tenant): void
    {
        $hoje = \DateTimeImmutable::createFromInterface($this->relogio->now())->setTime(0, 0);
        if ($this->mensagens->tokensDaPessoaDesde($tenant, $pessoa, $hoje) >= $this->tetoPorDia) {
            throw new TetoDiarioDoChatException();
        }
    }
}
