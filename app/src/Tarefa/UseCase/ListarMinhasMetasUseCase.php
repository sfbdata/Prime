<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Tarefa\DTO\MinhasMetasOutput;
use App\Tarefa\Enum\AbaMetas;
use App\Tarefa\Repository\CaixaDaEsteiraRepository;
use App\Tarefa\Repository\TarefaRepository;
use App\Tarefa\Service\AgrupadorDeMetas;

/**
 * Monta a tela "Minhas Metas" de um usuário.
 *
 * **Quem:** o colaborador logado, e só ele — o usuário nunca vem do request, é sempre o da
 * sessão. Deixar um id de usuário entrar por query string abriria a lista de metas de um
 * colega a quem soubesse trocar o número (IDOR).
 *
 * **O quê:** a lista de metas do papel escolhido (a aba), já quebrada nos blocos que a tela
 * desenha, mais as contagens de cada aba e os quatro KPIs do topo. O trilho ("Seu andamento" e
 * "Precisa de atenção") olha TODAS as metas do usuário, sem a aba e sem os filtros (desenho 04).
 *
 * **Por quê:** a tela unia dois papéis numa lista só, e quem delega perdia a própria fila de
 * trabalho dentro do que tinha delegado. Ver `docs/specs/minhas-metas-abas.md`.
 *
 * **Cadeado** (spec pasta-cadeado D3): meta de pasta oculta pelo cadeado para o usuário (`$pastasOcultas`,
 * de `AcessoAPasta::idsOcultosPara()`) não entra na lista, nas contagens das abas, nos KPIs nem no trilho.
 *
 * **Esteira** (desenho 04 de 22/10): com o filtro `etapa` (clique numa caixa) e o `$escritorio`, a lista é a das
 * metas abertas daquela caixa de toda a equipe, no lugar do papel da aba. O nome que o escritório deu a cada
 * etapa (`CaixaDaEsteira`) vai junto, para o chip "i/8 · Etapa" da linha usar o mesmo nome da esteira.
 *
 * **Erros:** não lança. Aba desconhecida cai no padrão (`Sou responsável`) — é uma tela de
 * leitura, e derrubar a página por um parâmetro estranho na URL seria pior que ignorá-lo.
 */
final class ListarMinhasMetasUseCase
{
    private const KPIS_VAZIOS = ['atrasadas' => 0, 'proximas' => 0, 'sem_prazo' => 0, 'aguardando_revisao' => 0];

    public function __construct(
        private readonly TarefaRepository $repository,
        private readonly AgrupadorDeMetas $agrupador,
        private readonly ?CaixaDaEsteiraRepository $caixas = null,
    ) {
    }

    /**
     * @param array<string, string> $filtros  busca, status, prioridade, prazo
     * @param bool $soAListagem  recarga parcial (XHR do filtro): o topo da tela não é
     *                           redesenhado, então as 8 contagens dele seriam calculadas e
     *                           jogadas fora — a cada tecla digitada na busca.
     * @param list<int> $pastasOcultas ids das pastas ocultas pelo cadeado para o usuário
     * @param Tenant|null $escritorio  o escritório da sessão; sem ele não há filtro por etapa nem nomes da esteira
     */
    public function executar(
        User $usuario,
        AbaMetas $aba,
        array $filtros,
        bool $soAListagem = false,
        array $pastasOcultas = [],
        ?Tenant $escritorio = null,
    ): MinhasMetasOutput {
        $metas = $this->repository->findParaMinhasMetas($usuario, $aba, $filtros, $pastasOcultas, $escritorio);

        $ids        = array_map(static fn (Tarefa $m): int => (int) $m->getId(), $metas);
        $marcadores = $this->repository->carregarMarcadoresDaLista($usuario, $ids);

        return new MinhasMetasOutput(
            aba: $aba,
            grupos: $this->agrupador->agrupar($metas, $aba),
            contagensPorAba: $soAListagem ? [] : $this->repository->contarPorAba($usuario, $pastasOcultas),
            kpis: $soAListagem ? self::KPIS_VAZIOS : $this->repository->contarPainelMinhasMetas($usuario, $pastasOcultas),
            filtros: $filtros,
            acompanhadas: $marcadores['acompanhadas'],
            // O trilho também é redesenhado na recarga parcial (ele mora no fragmento), então estes dois não
            // dependem de `$soAListagem`.
            andamento: $this->repository->contarAndamento($usuario, $pastasOcultas),
            atencao: $this->repository->findAtrasadasParaAtencao($usuario, MinhasMetasOutput::TETO_ATENCAO, $pastasOcultas),
            nomesDasEtapas: $this->nomesDasEtapas($escritorio),
            prioridades: $soAListagem ? [] : $this->repository->findPrioridadesDoDia($usuario, MinhasMetasOutput::TETO_PRIORIDADES, $pastasOcultas),
        );
    }

    /** @return array<string, string> valor de `EtapaDaPasta` → nome dado pelo escritório (só as renomeadas) */
    private function nomesDasEtapas(?Tenant $escritorio): array
    {
        if ($escritorio === null || $this->caixas === null) {
            return [];
        }

        $nomes = [];
        foreach ($this->caixas->configuradasDoEscritorio($escritorio) as $etapa => $caixa) {
            if ($caixa->getNomeDaEtapa() !== null) {
                $nomes[$etapa] = $caixa->getNomeDaEtapa();
            }
        }

        return $nomes;
    }
}
