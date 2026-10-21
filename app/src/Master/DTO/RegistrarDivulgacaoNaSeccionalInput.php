<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Entity\Auth\User;

/**
 * Pedido de registrar uma divulgação numa seccional (POST /master/seccionais/{uf}/divulgacoes, JSON
 * `{tipo, data?, nota?}`), vindo do menu de botão direito do mapa. `doJson()` só apara: texto vazio vira
 * nulo (data nula = hoje). As regras ficam no {@see \App\Master\UseCase\RegistrarDivulgacaoNaSeccionalUseCase}.
 */
final readonly class RegistrarDivulgacaoNaSeccionalInput
{
    public function __construct(
        /** Quem registra: o Master (ROLE_SUPER_ADMIN, exigido no controller). */
        public ?User $ator,
        public string $uf,
        public string $tipo,
        public ?string $data = null,
        public ?string $nota = null,
    ) {
    }

    /** @param array<mixed> $corpo */
    public static function doJson(?User $ator, string $uf, array $corpo): self
    {
        return new self($ator, $uf, self::texto($corpo['tipo'] ?? null) ?? '', self::texto($corpo['data'] ?? null), self::texto($corpo['nota'] ?? null));
    }

    private static function texto(mixed $valor): ?string
    {
        $texto = is_string($valor) ? trim($valor) : '';

        return $texto === '' ? null : $texto;
    }
}
