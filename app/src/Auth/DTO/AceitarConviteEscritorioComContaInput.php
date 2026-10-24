<?php

declare(strict_types=1);

namespace App\Auth\DTO;

use App\Entity\Auth\User;

/**
 * Pelo token, vem do link do e-mail (`/convite/{token}`). Pelo id (`porId`), vem da lista do convidado logado
 * (Meus convites, seleção de escritório), que não leva o token (achado de auditoria interna F6).
 */
readonly class AceitarConviteEscritorioComContaInput
{
    public function __construct(
        public ?string $token,
        public User $usuarioAtual,
        public ?int $conviteId = null,
    ) {}

    public static function porId(int $conviteId, User $usuarioAtual): self
    {
        return new self(null, $usuarioAtual, $conviteId);
    }
}
