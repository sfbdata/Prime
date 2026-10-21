<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

use App\Entity\Tarefa\Tarefa;
use App\Tarefa\Enum\AbaMetas;

/**
 * Tudo o que a tela "Minhas Metas" precisa para desenhar uma aba: os blocos da lista, o
 * número de cada aba, os quatro KPIs do topo e o trilho. "Seu andamento" e "Precisa de atenção"
 * olham todas as metas da pessoa (`$andamento`, `$atencao`), não a aba nem os filtros.
 */
final class MinhasMetasOutput
{
    /** Teto de linhas dos blocos do trilho — além disso a coluna vira uma segunda lista. */
    private const TETO_TRILHO = 5;

    /** Teto do "Precisa de atenção" (desenho 04, dc `atencao`: `.slice(0, 4)`), sem "Ver todas". */
    public const TETO_ATENCAO = 4;

    /**
     * @param GrupoDeMetasOutput[]                                                      $grupos
     * @param array<string, int>                                                        $contagensPorAba
     * @param array{atrasadas: int, proximas: int, sem_prazo: int, aguardando_revisao: int} $kpis
     * @param array<string, string>                                                     $filtros
     * @param PessoaNoTrilhoOutput[]                                                    $pessoas
     * @param int[]                                                                     $acompanhadas
     * @param array<int, int>                                                           $mensagensPorMeta
     * @param array<int, int>                                                           $numerosNaPasta
     * @param Tarefa[]                                                                  $atencao
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
        public readonly AndamentoDasMetasOutput $andamento = new AndamentoDasMetasOutput(),
        public readonly array $atencao = [],
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
     * As metas do bloco "Precisa de atenção" (desenho 04, dc `atencao`): as atrasadas de TODAS as metas
     * da pessoa, da que venceu primeiro, no máximo `TETO_ATENCAO`. Não dependem da aba nem dos filtros:
     * vêm prontas de `TarefaRepository::findAtrasadasParaAtencao`. O desenho não tem "Ver todas".
     *
     * @return Tarefa[]
     */
    public function metasQuePrecisamDeAtencao(): array
    {
        return array_slice($this->atencao, 0, self::TETO_ATENCAO);
    }

    /**
     * Legenda de "Seu andamento" (desenho 04, dc `andLeg`, P:111-117 e P:177), sobre TODAS as metas da
     * pessoa, como o "X de Y" acima dela: não muda com a aba nem com os filtros da tela.
     *
     * @return array<int, array{chave: string, total: int, tom: string}>  `chave` é do catálogo (ICU, `{total}`)
     */
    public function fatiasDoAndamento(): array
    {
        // Os três itens do desenho, na ordem dele, inclusive zerados: o leitor procura o número no mesmo
        // lugar toda vez. "em aberto" saiu por decisão do dono de 21/10 (revoga a de 14/10); por isso a
        // legenda não soma o "Y".
        $a = $this->andamento;

        return [
            ['chave' => 'metas.trilho.legenda.concluidas', 'total' => $a->concluidas, 'tom' => 'ok'],
            ['chave' => 'metas.trilho.legenda.atrasadas', 'total' => $a->atrasadas, 'tom' => 'danger'],
            ['chave' => 'metas.trilho.legenda.em_revisao', 'total' => $a->emRevisao, 'tom' => 'accent'],
        ];
    }

    /** O "X" do "X de Y concluídas" do trilho, sobre todas as metas da pessoa. */
    public function totalConcluidas(): int
    {
        return $this->andamento->concluidas;
    }

    /** O "Y" do "X de Y concluídas" do trilho: todas as metas da pessoa, concluídas dos últimos 30 dias inclusive. */
    public function totalDoAndamento(): int
    {
        return $this->andamento->total;
    }

    public function percentualConcluido(): int
    {
        return $this->andamento->percentualConcluido();
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
        foreach (['busca', 'prioridade', 'prazo'] as $campo) {
            if (($this->filtros[$campo] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }
}
