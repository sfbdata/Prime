<?php

declare(strict_types=1);

namespace App\Shared\Service;

/**
 * O que {@see ExecucaoDoGhostscript::rodarComTeto()} devolve: a saída padrão até o teto, o código
 * de saída e as duas marcas de parada forçada.
 *
 * Com qualquer marca ligada o processo já foi parado e a saída está incompleta: quem lê decide se
 * aproveita o que veio ou descarta.
 */
final readonly class SaidaDoGhostscript
{
    public function __construct(
        /** A saída padrão, nunca maior que o teto pedido. */
        public string $saida,
        /** O código de saída; `null` quando o sistema não informou. Morte por sinal fica acima de 128. */
        public ?int $codigo,
        public bool $tempoEstourado,
        public bool $tetoEstourado,
    ) {
    }

    /** Terminou sozinho, com código zero, sem estourar tempo nem teto. */
    public function terminouBem(): bool
    {
        return 0 === $this->codigo && !$this->tempoEstourado && !$this->tetoEstourado;
    }
}
