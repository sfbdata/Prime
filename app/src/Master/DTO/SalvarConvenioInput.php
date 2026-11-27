<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Entity\Auth\User;

/**
 * Pedido de criar ou editar um convênio (POST /master/convenios/salvar, JSON; spec docs/specs/convenios.md, §7.3).
 * `id` nulo cria. `codigo` opcional: o Master escolhe o código de convite (vazio sorteia). `doJson()` só dá forma ao que veio; as regras são do UseCase e da entidade.
 */
final class SalvarConvenioInput
{
    public function __construct(
        public readonly ?User $ator,
        public readonly ?int $id,
        public readonly string $nome,
        public readonly string $instituicao,
        public readonly string $abrangencia,
        public readonly ?string $uf,
        public readonly string $beneficio,
        public readonly ?int $beneficioValor,
        public readonly string $textoSelo,
        public readonly string $validoDe,
        public readonly ?string $validoAte,
        public readonly ?int $limiteDeUsos,
        public readonly bool $gerarOutroCodigo,
        public readonly ?string $codigo = null,
    ) {
    }

    /** @param array<mixed> $corpo */
    public static function doJson(?User $ator, array $corpo): self
    {
        return new self(
            $ator,
            self::inteiro($corpo['id'] ?? null),
            self::texto($corpo['nome'] ?? null),
            self::texto($corpo['instituicao'] ?? null),
            self::texto($corpo['abrangencia'] ?? null),
            self::textoOuNulo($corpo['uf'] ?? null),
            self::texto($corpo['beneficio'] ?? null),
            self::inteiro($corpo['beneficioValor'] ?? null),
            self::texto($corpo['textoSelo'] ?? null),
            self::texto($corpo['validoDe'] ?? null),
            self::textoOuNulo($corpo['validoAte'] ?? null),
            self::inteiro($corpo['limiteDeUsos'] ?? null),
            ($corpo['gerarOutroCodigo'] ?? false) === true,
            self::textoOuNulo($corpo['codigo'] ?? null),
        );
    }

    private static function texto(mixed $v): string
    {
        return is_string($v) ? trim($v) : '';
    }

    private static function textoOuNulo(mixed $v): ?string
    {
        $texto = self::texto($v);

        return $texto === '' ? null : $texto;
    }

    private static function inteiro(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v;
        }

        return is_string($v) && preg_match('/^\d{1,9}$/', trim($v)) === 1 ? (int) trim($v) : null;
    }
}
