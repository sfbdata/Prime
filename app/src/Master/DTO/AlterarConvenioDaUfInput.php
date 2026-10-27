<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Entity\Auth\User;

/**
 * Pedido de mudança do convênio OAB de um estado (POST /master/convenios, JSON `{uf, modo, valor, desde, motivo}`).
 * `modo`: `livre` (convênio ativo, grátis), `dif` (valor diferenciado) ou `sem` (sem convênio, mensalidade cheia).
 * `doJson()` só dá forma ao que veio; as regras (UF, modo, valor, data) são da entidade e do UseCase.
 */
final class AlterarConvenioDaUfInput
{
    public function __construct(
        public readonly ?User $ator,
        public readonly string $uf,
        public readonly string $modo,
        public readonly ?float $valor,
        public readonly string $desde,
        public readonly ?string $motivo,
    ) {
    }

    /** @param array<mixed> $corpo */
    public static function doJson(?User $ator, array $corpo): self
    {
        return new self(
            $ator,
            is_string($corpo['uf'] ?? null) ? $corpo['uf'] : '',
            is_string($corpo['modo'] ?? null) ? $corpo['modo'] : '',
            self::valor($corpo['valor'] ?? null),
            is_string($corpo['desde'] ?? null) ? $corpo['desde'] : '',
            is_string($corpo['motivo'] ?? null) ? $corpo['motivo'] : null,
        );
    }

    /** Reais em número ou texto ("9,90", "1.234,50", "9.90"); qualquer outra coisa vira nulo (o UseCase recusa). */
    private static function valor(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        if (!is_string($v)) {
            return null;
        }

        $texto = trim($v);
        if (str_contains($texto, ',')) {
            $texto = str_replace(',', '.', str_replace('.', '', $texto));
        }

        return preg_match('/^\d{1,6}(\.\d{1,2})?$/', $texto) === 1 ? (float) $texto : null;
    }
}
