<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

use App\Tarefa\Enum\AbaMetas;

/**
 * Tudo o que a tela "Minhas Metas" precisa para desenhar uma aba: os blocos da lista, o
 * número de cada aba e os quatro KPIs do topo.
 */
final class MinhasMetasOutput
{
    /** Teto de linhas dos blocos do trilho — além disso a coluna vira uma segunda lista. */
    private const TETO_TRILHO = 5;

    /**
     * @param GrupoDeMetasOutput[]                                                      $grupos
     * @param array<string, int>                                                        $contagensPorAba
     * @param array{atrasadas: int, proximas: int, sem_prazo: int, aguardando_revisao: int} $kpis
     * @param array<string, string>                                                     $filtros
     * @param PessoaNoTrilhoOutput[]                                                    $pessoas
     */
    public function __construct(
        public readonly AbaMetas $aba,
        public readonly array $grupos,
        public readonly array $contagensPorAba,
        public readonly array $kpis,
        public readonly array $filtros,
        public readonly bool $modoLista,
        public readonly array $pessoas = [],
    ) {
    }

    /** @return PessoaNoTrilhoOutput[] */
    public function pessoasVisiveis(): array
    {
        return array_slice($this->pessoas, 0, self::TETO_TRILHO);
    }

    public function pessoasOcultas(): int
    {
        return max(0, count($this->pessoas) - self::TETO_TRILHO);
    }

    public function grupo(string $chave): ?GrupoDeMetasOutput
    {
        foreach ($this->grupos as $grupo) {
            if ($grupo->chave === $chave) {
                return $grupo;
            }
        }

        return null;
    }

    /**
     * As metas do bloco "Precisa de atenção" — as atrasadas, com teto.
     *
     * @return \App\Entity\Tarefa\Tarefa[]
     */
    public function metasQuePrecisamDeAtencao(): array
    {
        return array_slice($this->grupo('atrasadas')?->metas ?? [], 0, self::TETO_TRILHO);
    }

    public function atrasadasOcultas(): int
    {
        return max(0, ($this->grupo('atrasadas')?->total() ?? 0) - self::TETO_TRILHO);
    }

    /**
     * As quatro fatias da barra de andamento. Somam o total exibido — se não somarem, algum
     * número está mentindo, e é por isso que saem todas do mesmo lugar (os grupos), e não de
     * contagens independentes.
     *
     * @return array<int, array{rotulo: string, total: int, tom: string}>
     */
    public function fatiasDoAndamento(): array
    {
        $noPrazo = ($this->grupo('proximas')?->total() ?? 0)
            + ($this->grupo('depois')?->total() ?? 0)
            + ($this->grupo('sem_prazo')?->total() ?? 0)
            + ($this->grupo('andamento')?->total() ?? 0);

        return array_values(array_filter([
            ['rotulo' => 'concluídas', 'total' => $this->totalConcluidas(), 'tom' => 'ok'],
            ['rotulo' => 'no prazo', 'total' => $noPrazo, 'tom' => 'warn'],
            ['rotulo' => 'atrasadas', 'total' => $this->grupo('atrasadas')?->total() ?? 0, 'tom' => 'danger'],
            ['rotulo' => 'em revisão', 'total' => $this->totalEmRevisao(), 'tom' => 'accent'],
        ], static fn (array $f): bool => $f['total'] > 0));
    }

    public function totalConcluidas(): int
    {
        return $this->grupo('concluidas')?->total() ?? 0;
    }

    public function percentualConcluido(): int
    {
        $total = $this->totalExibido();

        return $total === 0 ? 0 : (int) round($this->totalConcluidas() / $total * 100);
    }

    private function totalEmRevisao(): int
    {
        return ($this->grupo('em_revisao')?->total() ?? 0) + ($this->grupo('aguardando')?->total() ?? 0);
    }

    /** Quantas metas a aba está mostrando agora — soma dos blocos, concluídas incluídas. */
    public function totalExibido(): int
    {
        return array_sum(array_map(static fn (GrupoDeMetasOutput $g): int => $g->total(), $this->grupos));
    }

    public function estaVazia(): bool
    {
        return $this->grupos === [];
    }

    /** Verdadeiro quando o vazio veio de um filtro, e não de a aba não ter nada. */
    public function temFiltroAtivo(): bool
    {
        foreach (['busca', 'status', 'prioridade', 'prazo'] as $campo) {
            if (($this->filtros[$campo] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }
}
