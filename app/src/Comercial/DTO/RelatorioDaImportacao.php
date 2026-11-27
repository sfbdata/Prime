<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

/**
 * O relatório do CSV importado no público "Importar lista (CSV)" (A2 do teste do Farlei, 27/11/2026): quantas linhas
 * foram lidas, quantas valem, quantas repetem um e-mail já lido e quantas foram recusadas, com a lista das recusadas e o
 * motivo de cada uma. Gerado na hora, a partir do arquivo enviado na contagem; nada é gravado. A tela monta com a lista
 * o CSV das linhas recusadas para o Master corrigir.
 *
 * Linhas lidas = válidas + repetidas + inválidas + vazias. O cabeçalho (a primeira linha, sem nenhum `@`) e as linhas em
 * branco não entram na conta. `recusadas` traz cada linha recusada (no máximo as 5.000 linhas do CSV,
 * {@see \App\Comercial\Campanha\ListaInformada::LINHAS_DO_CSV}) com o número da linha no arquivo, o texto (cortado em
 * {@see self::TAMANHO_DA_LINHA} caracteres, para a resposta da contagem não pesar) e o motivo: `invalido`, `repetido` ou
 * `vazia`.
 */
final class RelatorioDaImportacao
{
    public const MOTIVO_INVALIDO = 'invalido';
    public const MOTIVO_REPETIDO = 'repetido';
    public const MOTIVO_VAZIA = 'vazia';
    public const TAMANHO_DA_LINHA = 200;

    /** @param list<array{linha: int, conteudo: string, motivo: string}> $recusadas */
    public function __construct(
        public readonly int $validas,
        public readonly int $repetidas,
        public readonly int $invalidas,
        public readonly int $vazias,
        public readonly array $recusadas,
    ) {
    }

    public function lidas(): int
    {
        return $this->validas + $this->repetidas + $this->invalidas + $this->vazias;
    }

    /**
     * @return array{lidas: int, validas: int, repetidas: int, invalidas: int, vazias: int, recusadas: list<array{linha: int, conteudo: string, motivo: string}>}
     */
    public function paraArray(): array
    {
        return [
            'lidas' => $this->lidas(), 'validas' => $this->validas, 'repetidas' => $this->repetidas,
            'invalidas' => $this->invalidas, 'vazias' => $this->vazias, 'recusadas' => $this->recusadas,
        ];
    }
}
