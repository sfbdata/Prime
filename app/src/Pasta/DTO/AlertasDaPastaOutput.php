<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * Os alertas da Controladoria da pasta, da mais grave para a menos, e quantos são urgentes (todos menos os de nível baixo).
 */
final readonly class AlertasDaPastaOutput
{
    /** @param list<AlertaDaPasta> $alertas */
    public function __construct(
        public array $alertas,
        public int $urgentes,
    ) {
    }

    /** @param list<AlertaDaPasta> $alertas */
    public static function de(array $alertas): self
    {
        return new self($alertas, \count(array_filter($alertas, static fn (AlertaDaPasta $a): bool => $a->nivel !== AlertaDaPasta::BAIXA)));
    }
}
