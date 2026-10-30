<?php

declare(strict_types=1);

namespace App\Calculo\DTO;

/** Pagamento já feito, corrigido até a data final (sem juros). */
final readonly class AbatimentoCorrigido
{
    public function __construct(
        public string $data,
        public string $descricao,
        public float $valor,
        public float $fator,
        public float $corrigido,
    ) {
    }
}
