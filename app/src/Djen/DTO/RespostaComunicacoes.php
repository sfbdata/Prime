<?php

declare(strict_types=1);

namespace App\Djen\DTO;

/**
 * Resultado de uma página de consulta ao DJEN: o total que casa com o filtro (`count`, satura em 10.000)
 * e os itens brutos da página (cada item é o array como veio da API, mapeado depois pelo Mapper).
 */
final class RespostaComunicacoes
{
    /**
     * @param list<array<string, mixed>> $items
     */
    public function __construct(
        public readonly int $count,
        public readonly array $items,
    ) {
    }

    public function quantidadeNaPagina(): int
    {
        return \count($this->items);
    }
}
