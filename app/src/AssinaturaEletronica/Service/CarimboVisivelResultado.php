<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

/**
 * O que o `CarimboVisivel` devolve: os bytes do PDF carimbado, ou a recusa com o texto do desenho (dc:384), pronta
 * para o aviso vermelho da tela.
 */
final readonly class CarimboVisivelResultado
{
    private function __construct(
        public ?string $pdf,
        public ?string $recusa,
    ) {
    }

    public static function carimbado(string $pdf): self
    {
        return new self($pdf, null);
    }

    public static function recusado(string $recusa): self
    {
        return new self(null, $recusa);
    }

    public function foiCarimbado(): bool
    {
        return $this->pdf !== null;
    }
}
