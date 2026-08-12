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
     * `$comparadas` e as `$faixas` contam **linhas do espelho**, não boletos (§6.4 e INV-CB4 da
     * {@see \App\Cobranca\Service\Espelho\CalibracaoDoEspelho}) — a contabilidade calcula e arredonda
     * por linha, e medir por boleto criava diferença de arredondamento que não existe na conta deles.
     *
     * @param array<string, int>                                                            $faixas  rótulo => quantidade
     * @param list<array{unidade: string, nn: ?string, classe: ?string, campo: string, nosso: int, deles: int, diferenca: int}> $piores
     */
    public function __construct(
        public string $carteira,
        public ?\DateTimeImmutable $dadosAte,
        public int $comparadas,
        public int $semParNoSistema,
        public int $semAtraso,
        public int $semBase,
        public array $faixas,
        public array $piores,
    ) {
    }

    /**
     * As linhas que ficaram fora, somadas — mas os três motivos são contados SEPARADOS de propósito.
     *
     * Um contador só diria a mesma frase para "300 linhas não casaram com o sistema" (defeito grave de
     * casamento) e para "300 linhas ainda não venceram" (nada demais). E o `semBase` é o único que
     * esconde não-espelhamento: ver INV-CB3 em {@see \App\Cobranca\Service\Espelho\CalibracaoDoEspelho}.
     */
    public function foraDaCalibracao(): int
    {
        return $this->semParNoSistema + $this->semAtraso + $this->semBase;
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
     *
     * ⚠️ **`bate quase` é ATÉ UM CENTAVO**, como a §6.4 escreve (*"bate quase (centavos)"*). A versão
     * anterior somava também a faixa "até 1 real" e imprimia sucesso verde: R$ 0,99 por linha, em
     * milhares de linhas, é dinheiro de verdade e não é arredondamento. Arredondamento de inteiro em
     * centavos não passa de 1 centavo por conta — qualquer coisa acima disso é regra diferente.
     */
    public function veredito(): string
    {
        if ($this->comparadas === 0) {
            return 'sem dado';
        }

        if ($this->exatas() === $this->comparadas) {
            return 'bate';
        }

        $ateUmCentavo = ($this->faixas['exato'] ?? 0) + ($this->faixas['ate 1 centavo'] ?? 0);

        return $ateUmCentavo === $this->comparadas ? 'bate quase' : 'nao bate';
    }
}
