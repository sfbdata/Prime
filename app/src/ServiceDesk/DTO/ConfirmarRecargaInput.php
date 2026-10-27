<?php

declare(strict_types=1);

namespace App\ServiceDesk\DTO;

use App\Entity\Auth\User;

final class ConfirmarRecargaInput
{
    public const ORIGEM_MANUAL = 'manual';

    /**
     * @param string $origem quem confirmou o pagamento: 'manual' (Master) ou o nome do provedor ('cora'...)
     * @param User|null $confirmadoPor usuário do Master na confirmação manual; null quando foi o gateway
     */
    public function __construct(
        public readonly string $referencia,
        public readonly int $valorCentavos,
        public readonly ?string $idExterno = null,
        public readonly string $origem = self::ORIGEM_MANUAL,
        public readonly ?User $confirmadoPor = null,
    ) {
    }
}
