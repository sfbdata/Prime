<?php

declare(strict_types=1);

namespace App\Dashboard\DTO;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/** Encerrar o acompanhamento aberto de uma pessoa: escritório e gestor da sessão; o id vem da URL. */
final readonly class EncerrarAcompanhamentoInput
{
    public function __construct(
        public Tenant $tenant,
        public User $ator,
        public int $pessoaId,
        public \DateTimeImmutable $agora,
    ) {
    }
}
