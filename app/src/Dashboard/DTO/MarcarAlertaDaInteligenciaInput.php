<?php

declare(strict_types=1);

namespace App\Dashboard\DTO;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/** Pedido de marca num alerta da Central de Inteligência: escritório e usuário vêm da sessão. */
final class MarcarAlertaDaInteligenciaInput
{
    public function __construct(
        public readonly Tenant $tenant,
        public readonly User $usuario,
        /** Id do alerta (MotorAvancado::IDS). */
        public readonly string $alerta,
        /** resolvido | ignorado | sim | nao (MarcasDosAlertas::acoes()). */
        public readonly string $acao,
    ) {
    }
}
