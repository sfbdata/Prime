<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Entity\Auth\User;

/**
 * Pedido de registrar a divulgação numa seccional (POST /master/seccionais/{uf}/divulgacoes, JSON
 * `{status, cor, data?}`), vindo do menu de botão direito do mapa. `doJson()` só apara: texto vazio vira
 * nulo (data nula = hoje). As regras ficam no {@see \App\Master\UseCase\RegistrarDivulgacaoNaSeccionalUseCase}.
 */
final readonly class RegistrarDivulgacaoNaSeccionalInput
{
    public function __construct(
        /** Quem registra: o Master (ROLE_SUPER_ADMIN, exigido no controller). */
        public ?User $ator,
        public string $uf,
        /** Um dos seis status do desenho (env, vis, neg, cli, ret, sem). */
        public string $status,
        /** Cor da paleta, #rrggbb. */
        public string $cor,
        public ?string $data = null,
    ) {
    }

    /** @param array<mixed> $corpo */
    public static function doJson(?User $ator, string $uf, array $corpo): self
    {
        return new self($ator, $uf, self::texto($corpo['status'] ?? null) ?? '', self::texto($corpo['cor'] ?? null) ?? '', self::texto($corpo['data'] ?? null));
    }

    private static function texto(mixed $valor): ?string
    {
        $texto = is_string($valor) ? trim($valor) : '';

        return $texto === '' ? null : $texto;
    }
}
