<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

/** O resultado da importação do CSV: novos, já existentes (atualizados) e endereços inválidos (contados e ignorados). */
final readonly class ImportacaoDeContatosOutput
{
    public function __construct(
        public int $novos,
        public int $atualizados,
        public int $invalidos,
    ) {
    }

    /** @return array{novos: int, atualizados: int, invalidos: int} */
    public function paraJson(): array
    {
        return ['novos' => $this->novos, 'atualizados' => $this->atualizados, 'invalidos' => $this->invalidos];
    }
}
