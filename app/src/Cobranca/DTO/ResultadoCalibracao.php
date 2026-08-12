<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

/**
 * Quão bem a nossa fórmula reproduz a da contabilidade
 * (SPEC docs/specs/cobranca-espelho-da-contabilidade.md §6).
 *
 * Sob a premissa do módulo — a contabilidade é a verdade, o sistema traduz —, o cálculo ao vivo é
 * uma **projeção** entre um import e o próximo. Este resultado diz se a projeção é confiável, e é
 * ele que decide entre "seguir com o cálculo ao vivo" e "reancorar a cada import".
 */
final readonly class ResultadoCalibracao
{
    /**
     * @param array<string, int>                                                            $faixas  rótulo => quantidade
     * @param list<array{unidade: string, nn: ?string, campo: string, nosso: int, deles: int, diferenca: int}> $piores
     */
    public function __construct(
        public string $carteira,
        public ?\DateTimeImmutable $dadosAte,
        public int $comparadas,
        public int $foraDaCalibracao,
        public array $faixas,
        public array $piores,
    ) {
    }

    /** Quantas bateram ao centavo, somando os quatro encargos. */
    public function exatas(): int
    {
        return $this->faixas['exato'] ?? 0;
    }

    public function percentualExato(): float
    {
        return $this->comparadas === 0 ? 0.0 : ($this->exatas() / $this->comparadas) * 100;
    }

    /**
     * O veredito da §6.4, nas três palavras que a spec define.
     *
     * - `bate` — a projeção está validada, segue como está;
     * - `bate quase` — validada COM reancoragem a cada import (a diferença zera no import seguinte);
     * - `nao bate` — existe divergência de REGRA. Achado para o dono levar à contabilidade.
     *   ⛔ Não se ajusta a fórmula para caber na planilha sem entender a causa.
     */
    public function veredito(): string
    {
        if ($this->comparadas === 0) {
            return 'sem dado';
        }

        if ($this->exatas() === $this->comparadas) {
            return 'bate';
        }

        $ateUmReal = ($this->faixas['exato'] ?? 0)
            + ($this->faixas['ate 1 centavo'] ?? 0)
            + ($this->faixas['ate 1 real'] ?? 0);

        return $ateUmReal === $this->comparadas ? 'bate quase' : 'nao bate';
    }
}
