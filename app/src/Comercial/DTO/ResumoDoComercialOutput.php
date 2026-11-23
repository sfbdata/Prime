<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

/**
 * Os 4 KPIs do Comercial (desenho 05 VS 21.10.26, L325 e L690): campanhas, e-mails na fila, base e descadastrados.
 * `testes` acompanha o KPI "Campanhas", que não os conta (pedido do Farlei M8.3): a descrição diz quantos ficaram à parte.
 */
final readonly class ResumoDoComercialOutput
{
    public function __construct(
        public int $campanhas,
        public int $naFila,
        public int $base,
        public int $descadastrados,
        public int $testes = 0,
    ) {
    }

    /** @return array{campanhas: int, naFila: int, base: int, descadastrados: int, testes: int} */
    public function paraJson(): array
    {
        return ['campanhas' => $this->campanhas, 'naFila' => $this->naFila, 'base' => $this->base, 'descadastrados' => $this->descadastrados, 'testes' => $this->testes];
    }
}
