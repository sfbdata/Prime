<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Entity\Auth\User;

/**
 * Pedido de editar a sede de uma seccional (POST /master/seccionais/{uf}/sede, JSON
 * `{endereco?, telefone?, site?}`). `doJson()` só apara: texto vazio vira nulo. Tamanho e formato do site
 * são regras do {@see \App\Master\UseCase\EditarSedeDaSeccionalUseCase}.
 */
final readonly class EditarSedeDaSeccionalInput
{
    public function __construct(
        /** Quem edita: o Master (ROLE_SUPER_ADMIN, exigido no controller). */
        public ?User $ator,
        public string $uf,
        public ?string $endereco = null,
        public ?string $telefone = null,
        public ?string $site = null,
    ) {
    }

    /** @param array<mixed> $corpo */
    public static function doJson(?User $ator, string $uf, array $corpo): self
    {
        return new self($ator, $uf, self::texto($corpo['endereco'] ?? null), self::texto($corpo['telefone'] ?? null), self::texto($corpo['site'] ?? null));
    }

    private static function texto(mixed $valor): ?string
    {
        $texto = is_string($valor) ? trim($valor) : '';

        return $texto === '' ? null : $texto;
    }
}
