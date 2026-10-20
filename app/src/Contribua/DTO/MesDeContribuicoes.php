<?php

declare(strict_types=1);

namespace App\Contribua\DTO;

/** Um mês da grade do relatório de contribuições: o confirmado (pelo banco) e o só informado pelo escritório. */
final readonly class MesDeContribuicoes
{
    public function __construct(
        /** `AAAA-MM`. */
        public string $mes,
        /** Como a grade mostra: `out/26`. */
        public string $rotulo,
        public int $confirmadoCentavos,
        public int $informadoCentavos,
    ) {
    }
}
