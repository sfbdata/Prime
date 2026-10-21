<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

/** Os 4 KPIs do Comercial (desenho 05 VS 21.10.26, L325 e L690): campanhas, e-mails na fila, base e descadastrados. */
final readonly class ResumoDoComercialOutput
{
    public function __construct(
        public int $campanhas,
        public int $naFila,
        public int $base,
        public int $descadastrados,
    ) {
    }

    /** @return array{campanhas: int, naFila: int, base: int, descadastrados: int} */
    public function paraJson(): array
    {
        return ['campanhas' => $this->campanhas, 'naFila' => $this->naFila, 'base' => $this->base, 'descadastrados' => $this->descadastrados];
    }
}
