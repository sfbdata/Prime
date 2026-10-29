<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

use App\Entity\Tarefa\Tarefa;
use App\Tarefa\Enum\AbaMetas;
use App\Tarefa\Enum\EtapaDaPasta;

/**
 * Tudo o que a tela "Minhas Metas" precisa para desenhar uma aba: os blocos da lista, o
 * número de cada aba, os quatro KPIs do topo e o trilho. "Seu andamento" e "Precisa de atenção"
 * olham todas as metas da pessoa (`$andamento`, `$atencao`), não a aba nem os filtros.
 */
final class MinhasMetasOutput
{
    /** Teto do "Precisa de atenção" (desenho 04, dc `atencao`: `.slice(0, 4)`), sem "Ver todas". */
    public const TETO_ATENCAO = 4;

    /** Teto do "O que fazer primeiro" do painel BlueJus IA (desenho 04 VS 29.10.26, dc `iaPrio`: `.slice(0, 5)`). */
    public const TETO_PRIORIDADES = 5;

    /**
     * @param GrupoDeMetasOutput[]                                                      $grupos
     * @param array<string, int>                                                        $contagensPorAba
     * @param array{atrasadas: int, proximas: int, sem_prazo: int, aguardando_revisao: int} $kpis
     * @param array<string, string>                                                     $filtros
     * @param int[]                                                                     $acompanhadas
     * @param Tarefa[]                                                                  $atencao
     * @param Tarefa[]                                                                  $prioridades "O que fazer primeiro" do painel BlueJus IA
     * @param array<string, string>                                                     $nomesDasEtapas etapa → nome dado pelo escritório
     */
    public function __construct(
        public readonly AbaMetas $aba,
        public readonly array $grupos,
        public readonly array $contagensPorAba,
        public readonly array $kpis,
        public readonly array $filtros,
        public readonly array $acompanhadas = [],
        public readonly AndamentoDasMetasOutput $andamento = new AndamentoDasMetasOutput(),
        public readonly array $atencao = [],
        public readonly array $nomesDasEtapas = [],
        public readonly array $prioridades = [],
    ) {
    }

    /**
     * Chip "i/T · Etapa" e "→ próximo: Etapa · Setor" da linha (desenho 04 de 22/10, dc L218 `faseItem`).
     * `posicao` é nula quando o tipo de demanda não passa por essa etapa; `proxima` é nula na última e na
     * concluída (o desenho esconde o "próximo" da concluída). `nome`/`nomeDaProxima` nulos = o do catálogo.
     *
     * @return array{etapa: EtapaDaPasta, posicao: ?int, total: int, nome: ?string, proxima: ?EtapaDaPasta, nomeDaProxima: ?string}
     */
    public function etapaDaMeta(Tarefa $meta): array
    {
        $etapa   = $meta->getEtapa();
        $tipo    = $meta->getTipoDeDemanda();
        $proxima = $meta->getStatus() === Tarefa::STATUS_CONCLUIDA ? null : $tipo->proximaDepoisDe($etapa);

        return [
            'etapa'         => $etapa,
            'posicao'       => $tipo->posicaoDe($etapa),
            'total'         => count($tipo->sequencia()),
            'nome'          => $this->nomesDasEtapas[$etapa->value] ?? null,
            'proxima'       => $proxima,
            'nomeDaProxima' => $proxima === null ? null : ($this->nomesDasEtapas[$proxima->value] ?? null),
        ];
    }

    /**
     * Vem pré-carregado do repositório, numa consulta para a lista inteira. Perguntar à entidade
     * (`meta.ehAcompanhadaPor`) custaria uma ida ao banco por linha, porque a coleção é lazy.
     */
    public function estaAcompanhando(int $metaId): bool
    {
        return in_array($metaId, $this->acompanhadas, true);
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

    public function estaVazia(): bool
    {
        return $this->grupos === [];
    }

    /** Dias de hoje até o prazo da meta (negativo = atrasada); nulo sem prazo. Dia do calendário, como o desenho. */
    public function diasParaOPrazo(Tarefa $meta): ?int
    {
        $prazo = $meta->getPrazo();

        return $prazo === null ? null : (int) (new \DateTimeImmutable('today'))->diff($prazo->setTime(0, 0))->format('%r%a');
    }
}
