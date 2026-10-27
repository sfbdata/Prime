<?php

declare(strict_types=1);

namespace App\Dashboard\DTO;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Criar ou renovar o acompanhamento de uma pessoa: escritório e gestor vêm da sessão, nunca do corpo;
 * o id vem da URL (IDOR conferido no UseCase) e o prazo (1, 2 ou 7 dias) vem do corpo.
 */
final readonly class GerirAcompanhamentoInput
{
    public function __construct(
        public Tenant $tenant,
        public User $ator,
        public int $pessoaId,
        public int $prazoEmDias,
        public \DateTimeImmutable $agora,
    ) {
    }
}
