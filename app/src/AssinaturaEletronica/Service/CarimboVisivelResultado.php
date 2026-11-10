<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

/**
 * O que o `CarimboVisivel` devolve: os bytes do PDF carimbado, ou a recusa só com o motivo (os textos do protótipo).
 * O aviso vermelho da tela é o motivo mais " Use o original.", que o JS acrescenta (dc:384).
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
