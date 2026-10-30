<?php

declare(strict_types=1);

namespace App\Calculo\DTO;

/** Uma linha do demonstrativo: valor devido, fator, juros e total. `mesesProjetados` conta meses estimados pela média. */
final readonly class LinhaDoCalculo
{
    public function __construct(
        public string $data,
        public string $descricao,
        public float $valor,
        public float $fator,
        public float $corrigido,
        public float $jurosPct,
        public float $juros,
        public float $total,
        public bool $aVencer,
        public int $mesesProjetados,
    ) {
    }

    /** Selo PROJEÇÃO: parcela que ainda vai vencer (depois de hoje) e também depois da data final do cálculo. */
    public function projecao(string $ate): bool
    {
        return $this->aVencer && $this->data > $ate;
    }
}
