<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Entity\Auth\User;

/**
 * Pedido de troca do modo dos temas comemorativos (POST /master/temas/modo, JSON `{modo, manual?}`).
 *
 * `doJson()` só normaliza o formato: texto vazio no `manual` vira "nenhum tema escolhido". Modo e id do tema são
 * validados no UseCase, contra a lista de modos e o catálogo.
 */
final readonly class DefinirModoDoTemaInput
{
    public function __construct(
        /** Quem troca: o Master (ROLE_SUPER_ADMIN, exigido no controller). */
        public ?User $ator,
        public string $modo,
        public ?string $manual = null,
    ) {
    }

    /**
     * @param array<mixed> $corpo
     */
    public static function doJson(?User $ator, array $corpo): self
    {
        $modo = is_string($corpo['modo'] ?? null) ? $corpo['modo'] : '';
        $manual = is_string($corpo['manual'] ?? null) ? trim($corpo['manual']) : '';

        return new self($ator, $modo, $manual === '' ? null : $manual);
    }
}
