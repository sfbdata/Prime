<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

/**
 * Resultado de `CorrigirPastaJudicialDuplicadaUseCase::prever()`/`confirmar()` — um item por Caso de
 * Cobrança que perdeu a disputa pela pasta compartilhada (Problema B).
 *
 * @param list<array{
 *     pastaAntigaId: int,
 *     pastaAntigaNup: ?string,
 *     casoVencedorId: int,
 *     casoCorrigidoId: int,
 *     casoCorrigidoUnidade: string,
 *     casoCorrigidoPessoa: string,
 *     reaproveitouOrfa: bool,
 *     pastaNovaId: ?int,
 *     pastaNovaNup: ?string,
 *     pastaNovaNome: ?string,
 * }> $itens
 */
final class ResultadoCorrecaoPastaDuplicada
{
    public function __construct(
        public readonly bool $aplicou,
        public readonly array $itens,
    ) {
    }
}
