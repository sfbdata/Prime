<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Pdf\Diagramacao;

/**
 * O cursor das duas colunas (`avanca` e `xc` do `monta`, dc:401): enche a coluna da esquerda, depois a da direita.
 * O desenho desiste quando a segunda coluna enche; com `continuar` (E3-9), o texto segue numa página nova com a mesma
 * diagramação, a partir do alto das colunas.
 */
final class ColunasDaFolha
{
    public const XL = 104.0;
    public const XR = 930.0;
    public const VAO = 30.0;
    public const LARGURA = (self::XR - self::XL - self::VAO) / 2;
    /** BT2 = H - 164. */
    public const BASE = 1250.0;
    /** Alto das colunas na página de continuação: onde a introdução começaria (142) mais o respiro de 4. */
    public const TOPO_DA_CONTINUACAO = 146.0;

    public float $y;
    private int $coluna = 0;
    /** @var list<list<ElementoDaPagina>> */
    private array $paginas = [[]];

    public function __construct(
        private float $topo,
        private readonly bool $continuar,
    ) {
        $this->y = $topo;
    }

    /** Passa para a outra coluna se $altura não cabe; falso quando acabou o espaço (e não pode continuar). */
    public function avancar(float $altura): bool
    {
        if ($this->y + $altura > self::BASE) {
            ++$this->coluna;
            $this->y = $this->topo;
            if ($this->coluna >= 2 && $this->continuar) {
                $this->paginas[] = [];
                $this->coluna = 0;
                $this->topo = self::TOPO_DA_CONTINUACAO;
                $this->y = $this->topo;
            }
        }

        return $this->coluna < 2;
    }

    public function x(): float
    {
        return self::XL + $this->coluna * (self::LARGURA + self::VAO);
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
