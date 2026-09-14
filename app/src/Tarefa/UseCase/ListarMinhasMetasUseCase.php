<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Tarefa\DTO\MinhasMetasOutput;
use App\Tarefa\Enum\AbaMetas;
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
 * desenha, mais as contagens de cada aba e os quatro KPIs do topo.
 *
 * **Por quê:** a tela unia dois papéis numa lista só, e quem delega perdia a própria fila de
 * trabalho dentro do que tinha delegado. Ver `docs/specs/minhas-metas-abas.md`.
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
    ) {
    }

    /**
     * @param array<string, string> $filtros  busca, status, prioridade, prazo
     * @param bool $soAListagem  recarga parcial (XHR do filtro): o topo da tela não é
     *                           redesenhado, então as 8 contagens dele seriam calculadas e
     *                           jogadas fora — a cada tecla digitada na busca.
     */
    public function executar(
        User $usuario,
        AbaMetas $aba,
        array $filtros,
        bool $modoLista = false,
        bool $soAListagem = false,
    ): MinhasMetasOutput {
        $metas = $this->repository->findParaMinhasMetas($usuario, $aba, $filtros);

        $marcadores = $this->repository->carregarMarcadoresDaLista(
            $usuario,
            array_map(static fn (Tarefa $m): int => (int) $m->getId(), $metas),
        );

        return new MinhasMetasOutput(
            aba: $aba,
            grupos: $this->agrupador->agrupar($metas, $aba),
            contagensPorAba: $soAListagem ? [] : $this->repository->contarPorAba($usuario),
            kpis: $soAListagem ? self::KPIS_VAZIOS : $this->repository->contarPainelMinhasMetas($usuario),
            filtros: $filtros,
            modoLista: $modoLista,
            // O trilho não é desenhado no modo lista — não vale pagar duas consultas por ele.
            pessoas: $modoLista ? [] : $this->repository->contarPessoasDoTrilho($usuario, $aba),
            acompanhadas: $marcadores['acompanhadas'],
            mensagensPorMeta: $marcadores['mensagens'],
        );
    }
}
