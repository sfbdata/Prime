<?php

declare(strict_types=1);

namespace App\Tenant\DTO;

/**
 * Resultado da purga (ou simulação de purga) de um escritório: quantas linhas foram
 * (ou seriam) apagadas por tabela e quantos arquivos em disco foram removidos.
 *
 * Desde a E2.5 o disco nunca faz a purga "falhar" depois do COMMIT: o que não saiu aparece em
 * `arquivosNaoRemovidos` (sem prova de pertencimento, ou falha do storage), e os anexos que a purga
 * ainda não sabe endereçar (Tarefa, até a E2.7) em `arquivosForaDoEscopo`. Depois do COMMIT as
 * linhas somem — estas listas e o log são o único rastro para a limpeza manual.
 */
final class PurgaEscritorioResultado
{
    /**
     * @param array<string,int> $linhasPorTabela      tabela => nº de linhas apagadas (ou que seriam)
     * @param list<string>      $arquivosNaoRemovidos na simulação, o que ficaria
     * @param list<string>      $arquivosForaDoEscopo
     * @param int               $arquivosPrevistos    só na simulação: quantos a purga removeria
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly string $nome,
        public readonly bool $simulado,
        public readonly array $linhasPorTabela,
        public readonly int $arquivosRemovidos,
        public readonly array $arquivosNaoRemovidos = [],
        public readonly array $arquivosForaDoEscopo = [],
        public readonly int $arquivosPrevistos = 0,
    ) {
    }

    /** Ficou (ou ficaria) arquivo no disco que precisa de atenção manual. */
    public function teveSobraNoDisco(): bool
    {
        return $this->arquivosNaoRemovidos !== [] || $this->arquivosForaDoEscopo !== [];
    }

    /**
     * Sobra que é ANOMALIA — sem prova de pertencimento ou falha do storage. Os anexos de Tarefa
     * ficam de fora de propósito (E2.7) e não contam: são dívida conhecida, não alerta.
     */
    public function teveArquivoNaoRemovido(): bool
    {
        return $this->arquivosNaoRemovidos !== [];
    }

    public function totalLinhas(): int
    {
        return array_sum($this->linhasPorTabela);
    }
}
