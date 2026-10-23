<?php

declare(strict_types=1);

namespace App\Auth\DTO;

/** Pelo id: o token do convite só existe no link do e-mail (achado de auditoria interna F4). */
readonly class RevogarConviteInput
{
    public function __construct(
        public int $conviteId,
    ) {}
}
