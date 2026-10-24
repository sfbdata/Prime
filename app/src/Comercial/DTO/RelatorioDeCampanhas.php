<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

/**
 * A lista do Relatório (desenho 05 VS 21.10.26, L301-304): a contagem de cada aba (sem a busca, como o dc), a página
 * pedida e a faixa "1 a 50 de N" (o dc usa um traço entre os números; a regra do texto sem travessão o troca por "a").
 */
final class RelatorioDeCampanhas
{
    /**
     * @param array{env: int, ag: int, rec: int, est: int, arq: int} $abas
     * @param list<LinhaDoRelatorio>             $linhas
     */
    public function __construct(
        public readonly string $aba,
        public readonly array $abas,
        public readonly array $linhas,
        public readonly int $total,
        public readonly int $pagina,
        public readonly int $porPagina,
    ) {
    }

    public function paginas(): int
    {
        return max(1, (int) ceil($this->total / $this->porPagina));
    }

    public function faixa(): string
    {
        if ($this->total === 0) {
            return '0 de 0';
        }
        $de = ($this->pagina - 1) * $this->porPagina + 1;

        return $de . ' a ' . min($this->total, $de + $this->porPagina - 1) . ' de ' . $this->total;
    }

    /** @return array<string, mixed> */
    public function paraArray(): array
    {
        return [
            'aba' => $this->aba, 'abas' => $this->abas, 'total' => $this->total, 'pagina' => $this->pagina,
            'paginas' => $this->paginas(), 'faixa' => $this->faixa(),
            'campanhas' => array_map(static fn (LinhaDoRelatorio $l): array => $l->paraArray(), $this->linhas),
        ];
    }
}
