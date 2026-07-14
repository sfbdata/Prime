<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

/**
 * Uma parcela gerada pelo parcelamento inteligente (Ajuste 7): descrição ("Parcela k/n"), valor em
 * CENTAVOS inteiros e vencimento. NÃO inclui a entrada (tratada à parte pelo UseCase). Value-object de
 * leitura, fonte única da aritmética do gerador — a prévia ao vivo e a criação consomem estas linhas.
 */
final class LinhaParcelaGerada
{
    public function __construct(
        public readonly string $descricao,
        public readonly int $valor,
        public readonly \DateTimeImmutable $vencimento,
    ) {
    }
}
