<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Entity\Auth\User;

/**
 * Pedido de remover uma subseção (POST /master/seccionais/{uf}/subsecoes/{id}/remover), depois da confirmação
 * da janela da OAB. A UF e o id vêm da rota; a subseção precisa ser daquela UF
 * ({@see \App\Master\UseCase\RemoverSubsecaoDaSeccionalUseCase}).
 */
final readonly class RemoverSubsecaoDaSeccionalInput
{
    public function __construct(
        /** Quem remove: o Master (ROLE_SUPER_ADMIN, exigido no controller). */
        public ?User $ator,
        public string $uf,
        public int $id,
    ) {
    }
}
