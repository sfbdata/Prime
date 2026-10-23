<?php

declare(strict_types=1);

namespace App\Auth\DTO;

use App\Entity\Auth\User;

readonly class ReenviarConviteInput
{
    /**
     * Pelo id: o token do convite só existe no link do e-mail (achado de auditoria interna F4).
     *
     * @param User $reenviadoPor quem pede o reenvio; com o e-mail a confirmar, o reenvio é recusado
     */
    public function __construct(
        public int $conviteId,
        public User $reenviadoPor,
    ) {}
}
