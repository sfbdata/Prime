<?php

declare(strict_types=1);

namespace App\Calculo\DTO;

/** Resultado completo: linhas, abatimentos e o agrupamento dos valores apurados (sem arredondar; a tela e o PDF arredondam). */
final readonly class ResultadoDoCalculo
{
    /**
     * @param list<LinhaDoCalculo>      $linhas
     * @param list<AbatimentoCorrigido> $abatimentos
     */
    public function __construct(
        public array $linhas,
        public array $abatimentos,
        public float $subtotal,
        public float $multa,
        public float $honorarios,
        public float $abatimentosCorrigidos,
        public float $debito,
        public float $multa523,
        public float $honorarios523,
        public float $total,
        public string $ate,
        public int $mesesSemIndice,
        public int $parcelasProjetadas,
    ) {
    }
}
