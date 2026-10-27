<?php

declare(strict_types=1);

namespace App\Dashboard\DTO;

/**
 * Uma linha de "Comparação com o período anterior": o número de agora e o do período anterior de mesma
 * duração, os dois REAIS. `variacao` (em %) só existe com anterior maior que zero: nada é estimado.
 * `sentido`: 'neutro' (subir não é bom nem ruim), 'sobe_bom' ou 'sobe_ruim'.
 */
final readonly class ComparacaoDaPessoaOutput
{
    public const NEUTRO    = 'neutro';
    public const SOBE_RUIM = 'sobe_ruim';

    public function __construct(
        public string $chave,
        public int $atual,
        public int $anterior,
        public string $sentido = self::NEUTRO,
    ) {
    }

    public function variacao(): ?int
    {
        return $this->anterior > 0 ? (int) round(($this->atual - $this->anterior) / $this->anterior * 100) : null;
    }

    /** Maior dos dois lados, a base das barras. */
    public function maior(): int
    {
        return max($this->atual, $this->anterior, 1);
    }
}
