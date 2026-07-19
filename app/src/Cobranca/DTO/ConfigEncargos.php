<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Enum\BaseEncargo;
use App\Cobranca\Enum\RegimeJuros;

/**
 * Configuração EFETIVA de encargos de uma obrigação (spec "encargos configuráveis em cascata" §4.1):
 * o resultado da cascata Carteira → Objeto/Caso → Obrigação já resolvida campo a campo pelo
 * `ResolvedorConfigEncargos`. É um valor imutável, sem I/O e sem dependência de entidade — a
 * `CalculadoraEncargos` só conhece este DTO.
 *
 * Todas as taxas em BASIS POINTS inteiros (100 bp = 1%; 1% a.m. = 100 bp; 2% = 200 bp). Nada de
 * float no caminho do dinheiro: o percentual decimal do banco é convertido para bp uma única vez,
 * na borda (ver `ResolvedorConfigEncargos`).
 *
 * O default de TODOS os campos é NEUTRO (taxa 0): um módulo em produção não pode começar a gerar
 * encargos sozinho por causa de um deploy (decisão D4). Quem quiser encargos, configura.
 */
final readonly class ConfigEncargos
{
    public function __construct(
        /** Juros de mora ao mês, em bp (100 = 1% a.m.). Pró-rata diária, mês = 30 dias. */
        public int $taxaJurosMensalBp = 0,
        public RegimeJuros $regimeJuros = RegimeJuros::Simples,
        /** Multa por atraso, em bp (200 = 2%). Aplicação ÚNICA — não cresce com o tempo. */
        public int $taxaMultaBp = 0,
        public BaseEncargo $baseMulta = BaseEncargo::Principal,
        /** Correção monetária, em bp. Default 0 (não usada na operação TOPLIFE). */
        public int $taxaCorrecaoBp = 0,
        public BaseEncargo $baseCorrecao = BaseEncargo::Principal,
        /**
         * Honorários advocatícios, em bp (2000 = 20%). NÃO é coluna nova (decisão D2): deriva do
         * `percentualHonorarios`/`formaHonorarios` que já existem no Caso e na Carteira.
         */
        public int $taxaHonorariosBp = 0,
        public BaseEncargo $baseHonorarios = BaseEncargo::Composta,
        /** Dias de atraso sem honorários. Corte ESTRITO: 30 ⇒ honorário só a partir do 31º dia. */
        public int $carenciaHonorariosDias = 0,
        /** Dias de atraso sem juros/multa. Default 0 — os dados provam que valem do 1º dia. */
        public int $toleranciaJurosMultaDias = 0,
    ) {
    }

    /**
     * Configuração NEUTRA: nenhuma taxa, nenhum encargo gerado. É o comportamento idêntico ao que o
     * módulo tem hoje (encargos só entram por digitação/importação) e o fallback seguro quando a
     * cascata não encontra nível algum configurado.
     */
    public static function neutra(): self
    {
        return new self();
    }

    /**
     * Default comprovado contra os dados reais da operação TOPLIFE (Apêndice A da spec, erro zero em
     * 4.413 linhas): juros 1% a.m. simples pró-rata; multa 2% do principal PURO (fixa, não cresce);
     * correção 0; honorários sobre a base composta (P+juros+multa+correção) a partir do 31º dia.
     *
     * A alíquota de honorários é o ÚNICO parâmetro que difere entre as carteiras (20% na I, 15% na
     * II) — por isso entra por argumento.
     */
    public static function padraoTopLife(int $taxaHonorariosBp): self
    {
        return new self(
            taxaJurosMensalBp: 100,
            regimeJuros: RegimeJuros::Simples,
            taxaMultaBp: 200,
            baseMulta: BaseEncargo::Principal,
            taxaCorrecaoBp: 0,
            baseCorrecao: BaseEncargo::Principal,
            taxaHonorariosBp: $taxaHonorariosBp,
            baseHonorarios: BaseEncargo::Composta,
            carenciaHonorariosDias: 30,
            toleranciaJurosMultaDias: 0,
        );
    }
}
