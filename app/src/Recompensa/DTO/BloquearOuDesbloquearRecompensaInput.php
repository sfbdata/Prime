<?php

declare(strict_types=1);

namespace App\Recompensa\DTO;

use App\Entity\Auth\User;
use App\Recompensa\Exception\RecompensaRecusada;

/** Bloquear ou desbloquear a recompensa de um indicador (POST /master/recompensas/usuarios/{id}/bloqueio, corpo `{bloqueado: bool}`). */
final readonly class BloquearOuDesbloquearRecompensaInput
{
    public function __construct(
        /** Quem altera: o Master (ROLE_SUPER_ADMIN, exigido no controller). */
        public ?User $ator,
        public int $userId,
        public bool $bloqueado,
    ) {
    }

    /**
     * @param array<mixed> $corpo
     *
     * @throws RecompensaRecusada quando `bloqueado` não é verdadeiro nem falso (sem adivinhar "1" ou "sim")
     */
    public static function doJson(?User $ator, int $userId, array $corpo): self
    {
        if (!is_bool($corpo['bloqueado'] ?? null)) {
            throw new RecompensaRecusada('Corpo da requisição inválido.');
        }

        return new self($ator, $userId, $corpo['bloqueado']);
    }
}
