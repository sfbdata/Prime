<?php

declare(strict_types=1);

namespace App\Dashboard\Inteligencia;

/** Uma linha de "Meta por colaborador" (dc L1301-1307): quantas pastas a mais a pessoa precisa criar e a largura da barra. */
final readonly class PessoaDoPlano
{
    public function __construct(
        public string $nome,
        public int $extra,
        public int $pctBarra,
    ) {
    }
}
