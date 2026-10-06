<?php

declare(strict_types=1);

namespace App\Tarefa\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Repository\UserRepository;
use App\Tarefa\DTO\MetasDaEquipeOutput;
use App\Tarefa\Repository\TarefaRepository;

/**
 * Monta a lista "Metas da equipe" (/tarefas/equipe).
 *
 * **Quem:** o gestor que vê o Dashboard (módulo `bi`) — a guarda é do controller.
 *
 * **O quê:** as metas que um número de metas da tabela Desempenho conta, para o mesmo
 * responsável/cargo/status/período. O número clicado TEM de bater com o total desta lista:
 * por isso o universo de colaboradores é montado exatamente como no ObterDadosDashboardUseCase
 * (ativos no escritório, estreitados por responsável e por cargo) e os critérios de status
 * moram no TarefaRepository, ao lado dos count*PorResponsavel.
 *
 * **Isolamento:** `responsavel` vem da URL, mas só é aceito se estiver no universo do
 * escritório corrente. Id de outro escritório (ou inexistente, ou desligado) dá lista vazia —
 * nem 500 nem o nome de quem quer que seja.
 *
 * **Erros:** não lança. Status desconhecido cai em `todas`; data inválida é ignorada (mesma
 * régua do Dashboard).
 */
final class ListarMetasDaEquipeUseCase
{
    public const POR_PAGINA = 25;

    /** status aceito na URL => rótulo do título */
    public const STATUS = [
        'todas'         => 'Metas',
        'ativas'        => 'Metas ativas',
        'vencidas'      => 'Metas vencidas',
        'prazo_proximo' => 'Prazos próximos',
    ];

    /** Status medidos contra "agora", sem o recorte de período (como no Dashboard). */
    private const RELATIVOS_A_REFERENCIA = ['vencidas', 'prazo_proximo'];

    public function __construct(
        private readonly TarefaRepository $tarefaRepository,
        private readonly UserRepository $userRepository,
    ) {
    }

    /**
     * @param array<string, mixed> $filtros  status, responsavel (userId), cargo (nome), data_de, data_ate (Y-m-d)
     */
    public function executar(Tenant $tenant, \DateTimeImmutable $referencia, array $filtros, int $pagina = 1): MetasDaEquipeOutput
    {
        $status = (string) ($filtros['status'] ?? '');
        if (!isset(self::STATUS[$status])) {
            $status = 'todas';
        }

        $respId = (int) ($filtros['responsavel'] ?? 0);
        $cargo  = trim((string) ($filtros['cargo'] ?? ''));
        $dataDe  = $this->parseData((string) ($filtros['data_de'] ?? ''));
        $dataAte = $this->parseData((string) ($filtros['data_ate'] ?? ''));

        $equipe = $this->montarEquipe($tenant, $respId, $cargo);
        $ids    = array_map(static fn (User $u): int => (int) $u->getId(), $equipe);

        $periodo = ['data_de' => $dataDe?->format('Y-m-d') ?? '', 'data_ate' => $dataAte?->format('Y-m-d') ?? ''];
        $pagina  = max(1, $pagina);

        $paginador = $this->tarefaRepository->findMetasDaEquipePaginado($tenant, $ids, $status, $periodo, $referencia, $pagina, self::POR_PAGINA);
        $total     = count($paginador);
        $totalPaginas = max(1, (int) ceil($total / self::POR_PAGINA));

        // Página além da última (link velho, filtro que encolheu): mostra a última, não um vazio falso.
        if ($pagina > $totalPaginas) {
            $pagina    = $totalPaginas;
            $paginador = $this->tarefaRepository->findMetasDaEquipePaginado($tenant, $ids, $status, $periodo, $referencia, $pagina, self::POR_PAGINA);
        }

        /** @var list<Tarefa> $metas */
        $metas = array_values(iterator_to_array($paginador->getIterator()));

        $relativo = in_array($status, self::RELATIVOS_A_REFERENCIA, true);

        return new MetasDaEquipeOutput(
            status:       $status,
            titulo:       $this->titulo($status, $respId, $cargo, $equipe, $relativo ? null : $dataDe, $relativo ? null : $dataAte, $referencia),
            descricao:    $this->descricao($status),
            metas:        $metas,
            total:        $total,
            pagina:       $pagina,
            totalPaginas: $totalPaginas,
            filtros:      array_filter([
                'status'      => $status,
                'responsavel' => $respId > 0 ? (string) $respId : '',
                'cargo'       => $cargo,
                // Vencidas/prazos não usam período: não carregar data na URL que não filtra nada.
                'data_de'     => $relativo ? '' : $periodo['data_de'],
                'data_ate'    => $relativo ? '' : $periodo['data_ate'],
            ], static fn (string $v): bool => $v !== ''),
        );
    }

    /**
     * Mesmo universo das linhas do Dashboard: colaboradores ativos do escritório, reduzidos ao
     * responsável escolhido e/ou ao cargo escolhido.
     *
     * @return list<User>
     */
    private function montarEquipe(Tenant $tenant, int $respId, string $cargo): array
    {
        $colaboradores = $this->userRepository->findColaboradoresAtivosPorTenant($tenant);

        if ($respId > 0) {
            $colaboradores = array_values(array_filter($colaboradores, static fn (User $u): bool => $u->getId() === $respId));
        }

        if ($cargo !== '' && $colaboradores !== []) {
            $mCargo        = $this->userRepository->findCargoPorColaboradores($tenant);
            $colaboradores = array_values(array_filter(
                $colaboradores,
                static fn (User $u): bool => ($mCargo[$u->getId()] ?? null) === $cargo,
            ));
        }

        return $colaboradores;
    }

    /**
     * "Metas vencidas — Fulano · 01/09–30/09".
     *
     * @param list<User> $equipe
     */
    private function titulo(
        string $status,
        int $respId,
        string $cargo,
        array $equipe,
        ?\DateTimeImmutable $de,
        ?\DateTimeImmutable $ate,
        \DateTimeImmutable $referencia,
    ): string {
        if ($respId > 0) {
            // Nome só sai do universo do escritório: id alheio não revela ninguém.
            $sujeito = $equipe !== [] ? $equipe[0]->getFullName() : 'colaborador não encontrado';
        } elseif ($cargo !== '') {
            $sujeito = $cargo;
        } else {
            $sujeito = 'Equipe';
        }

        $titulo  = self::STATUS[$status] . ' — ' . $sujeito;
        $periodo = $this->periodo($de, $ate, $referencia);

        return $periodo === '' ? $titulo : $titulo . ' · ' . $periodo;
    }

    private function periodo(?\DateTimeImmutable $de, ?\DateTimeImmutable $ate, \DateTimeImmutable $referencia): string
    {
        $anoCorrente = $referencia->format('Y');
        $curto = ($de === null || $de->format('Y') === $anoCorrente) && ($ate === null || $ate->format('Y') === $anoCorrente);
        $fmt   = $curto ? 'd/m' : 'd/m/Y';

        return match (true) {
            $de !== null && $ate !== null => $de->format($fmt) . '–' . $ate->format($fmt),
            $de !== null                  => 'desde ' . $de->format($fmt),
            $ate !== null                 => 'até ' . $ate->format($fmt),
            default                       => '',
        };
    }

    private function descricao(string $status): string
    {
        return match ($status) {
            'ativas'        => 'Metas não concluídas, criadas no período.',
            'vencidas'      => 'Metas não concluídas com o prazo já vencido — contadas até agora, sem recorte de período.',
            'prazo_proximo' => 'Metas não concluídas com prazo nos próximos 7 dias — sem recorte de período.',
            default         => 'Todas as metas criadas no período, em qualquer status.',
        };
    }

    /** Mesma régua do Dashboard: só Y-m-d válido conta; o resto é ignorado. */
    private function parseData(string $valor): ?\DateTimeImmutable
    {
        $valor = trim($valor);
        if ($valor === '') {
            return null;
        }

        $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $valor);

        return ($data !== false && $data->format('Y-m-d') === $valor) ? $data : null;
    }
}
