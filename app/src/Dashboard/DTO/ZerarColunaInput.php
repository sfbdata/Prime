<?php

declare(strict_types=1);

namespace App\Dashboard\DTO;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * "Zerar" de uma coluna da tabela Desempenho (DASH-014). Escritório e autor são SEMPRE os da
 * sessão; `pessoaId` nulo = a equipe toda.
 */
final class ZerarColunaInput
{
    public function __construct(
        public readonly Tenant $tenant,
        public readonly User $autor,
        public readonly string $coluna,
        public readonly ?int $pessoaId,
        public readonly \DateTimeImmutable $agora,
    ) {
    }
}
