<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf\Diagramacao;

/**
 * O cursor da página inteira (`nova`, `garante` e `TX` do `pdf()`, dc:395): páginas de elementos e a altura `y` onde
 * entra o próximo.
 */
final class Paginacao
{
    public float $y;
    /** @var list<list<ElementoDaPagina>> */
    private array $paginas = [[]];

    public function __construct(
        private readonly float $topo,
        private readonly float $base,
    ) {
        $this->y = $topo;
    }

    public function nova(): void
    {
        $this->paginas[] = [];
        $this->y = $this->topo;
    }

    /** Começa outra página se a altura $altura não cabe mais nesta. */
    public function garantir(float $altura): void
    {
        if ($this->y + $altura > $this->base) {
            $this->nova();
        }
    }

    public function noTopo(): bool
    {
        return $this->y <= $this->topo;
    }

    public function por(ElementoDaPagina $elemento): void
    {
        $this->paginas[array_key_last($this->paginas)][] = $elemento;
    }

    /** @return list<list<ElementoDaPagina>> */
    public function paginas(): array
    {
        return $this->paginas;
    }
}
