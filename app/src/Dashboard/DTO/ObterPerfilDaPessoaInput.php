<?php

declare(strict_types=1);

namespace App\Dashboard\DTO;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/** Pedido do perfil de uma pessoa: escritório e usuário vêm da sessão; o id vem da URL (IDOR conferido no UseCase). */
final readonly class ObterPerfilDaPessoaInput
{
    /**
     * @param array<string, mixed> $filtros       período do painel (data_de, data_ate)
     * @param list<int>            $pastasOcultas pastas ocultas pelo cadeado para quem olha
     */
    public function __construct(
        public Tenant $tenant,
        public User $usuario,
        public int $pessoaId,
        public array $filtros,
        public \DateTimeImmutable $agora,
        public array $pastasOcultas = [],
    ) {
    }
}
