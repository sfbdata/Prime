<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

use App\Entity\Tarefa\Tarefa;

use App\Tarefa\Enum\AbaMetas;
use App\Tarefa\Service\AgrupadorDeMetas;

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
     * @param int[]                                                                     $acompanhadas
     * @param array<int, int>                                                           $mensagensPorMeta
     * @param array<int, int>                                                           $numerosNaPasta
     */
    public function __construct(
        public readonly AbaMetas $aba,
        public readonly array $grupos,
        public readonly array $contagensPorAba,
        public readonly array $kpis,
        public readonly array $filtros,
        public readonly bool $modoLista,
        public readonly array $pessoas = [],
        public readonly array $acompanhadas = [],
        public readonly array $mensagensPorMeta = [],
        public readonly array $numerosNaPasta = [],
    ) {
    }

    /**
     * Estes dois vêm pré-carregados do repositório, em duas consultas para a lista inteira.
     * Perguntar à entidade (`meta.ehAcompanhadaPor`, `meta.mensagens|length`) custaria uma ida
     * ao banco por linha, porque as coleções são lazy.
     */
    public function estaAcompanhando(int $metaId): bool
    {
        return in_array($metaId, $this->acompanhadas, true);
    }

    /** Número da meta na sua pasta (R239); `null` se não veio pré-carregado — a tela não inventa. */
    public function numeroNaPasta(int $metaId): ?int
    {
        return $this->numerosNaPasta[$metaId] ?? null;
    }

    /** Pílula de status derivada do dado real, pela mesma regra que escolhe o grupo. */
    public function statusDaMeta(Tarefa $meta): StatusDoPrazoDaMeta
    {
        return StatusDoPrazoDaMeta::de($meta);
    }

    /** Texto do prazo da linha (desenho de 13/10), com o destaque só na atrasada — mesma regra do grupo. */
    public function prazoDaMeta(Tarefa $meta): TextoDoPrazoDaMeta
    {
        return TextoDoPrazoDaMeta::de($meta);
    }

    public function totalDeMensagens(int $metaId): int
    {
        return $this->mensagensPorMeta[$metaId] ?? 0;
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
     * Legenda de "Seu andamento". Sai das metas que a aba exibe, pela mesma regra que escolhe o grupo
     * (`AgrupadorDeMetas::classificar`), e não de contagens independentes.
     *
     * @return array<int, array{rotulo: string, total: int, tom: string}>
     */
    public function fatiasDoAndamento(): array
    {
        // Legenda do desenho de 13/10 (dc `andLeg`): sempre os mesmos três itens, na mesma ordem,
        // inclusive zerados — o leitor procura o número no mesmo lugar toda vez. As abertas que não
        // estão atrasadas nem em revisão não têm item próprio (o desenho também não as mostra).
        // Atrasadas pela regra, não pelo grupo: a aba Criei agrupa por etapa e não tem grupo
        // "Atrasadas", mas as delegadas atrasadas existem e o desenho as conta em qualquer aba.
        return [
            ['rotulo' => 'concluída(s)', 'total' => $this->totalConcluidas(), 'tom' => 'ok'],
            ['rotulo' => 'atrasada(s)', 'total' => $this->totalAtrasadas(), 'tom' => 'danger'],
            ['rotulo' => 'em revisão', 'total' => $this->totalEmRevisao(), 'tom' => 'accent'],
        ];
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

    private function totalAtrasadas(): int
    {
        $total = 0;
        foreach ($this->grupos as $grupo) {
            foreach ($grupo->metas as $meta) {
                if (AgrupadorDeMetas::classificar($meta) === 'atrasadas') {
                    ++$total;
                }
            }
        }

        return $total;
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
