<?php

declare(strict_types=1);

namespace App\Master\DTO;

/** Uma página da caixa da Central do Master (D9: 50 por página), com os contadores de todas as abas. */
final class CaixaDaCentralOutput
{
    /**
     * @param array<string, int>   $contagens por aba, sem a busca (como o desenho)
     * @param list<ItemDaCentral> $itens
     */
    public function __construct(
        public readonly string $aba,
        public readonly array $contagens,
        public readonly int $total,
        public readonly int $pagina,
        public readonly int $porPagina,
        public readonly array $itens,
    ) {
    }

    /** @return array<string, mixed> */
    public function emJson(): array
    {
        return [
            'aba' => $this->aba, 'abas' => $this->contagens, 'total' => $this->total, 'pagina' => $this->pagina,
            'porPagina' => $this->porPagina, 'itens' => array_map(static fn (ItemDaCentral $i): array => $i->emJson(), $this->itens),
        ];
    }
}
