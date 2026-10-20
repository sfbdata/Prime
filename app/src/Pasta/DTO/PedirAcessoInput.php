<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\NivelDeAcesso;
use App\Pasta\Entity\Pasta;

/** "Pedir acesso ao primário" (tela trancada) ou "Pedir para editar" (faixa de somente visualização). */
final class PedirAcessoInput
{
    public function __construct(
        public readonly Pasta $pasta,
        public readonly User $solicitante,
        public readonly Tenant $tenant,
        public readonly NivelDeAcesso $nivel,
        public readonly ?string $motivo,
    ) {
    }
}
