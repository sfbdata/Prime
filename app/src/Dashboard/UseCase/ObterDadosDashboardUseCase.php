<?php

declare(strict_types=1);

namespace App\Dashboard\UseCase;

use App\Dashboard\DTO\DashboardOutput;
use App\Dashboard\DTO\LinhaAdvogadoDashboardOutput;
use App\Dashboard\Repository\DashboardFotoRepository;
use App\Entity\Tenant\Tenant;
use App\Pasta\Repository\PastaRepository;
use App\Repository\UserRepository;
use App\Tarefa\Repository\TarefaRepository;

final class ObterDadosDashboardUseCase
{
    /**
     * Valor reservado do filtro `cargo` para "colaborador sem cargo". Não colide com nome de
     * cargo real e mantém o filtro antigo (`cargo=<nome>`) funcionando igual.
     */
    public const CARGO_SEM = '__sem__';

    /** Teto do termo de busca: o que passar disso não ajuda a achar ninguém. */
    private const BUSCA_MAX = 100;

    /**
     * Colunas que o cabeçalho da tabela deixa ordenar: chave usada no `data-ordenar` do <th>
     * => propriedade da linha. A chave chega pela URL, então tudo que não estiver aqui é
     * ignorado (ver ordenar()).
     */
    private const ORDENAVEIS = [
        'advogado'        => 'nomeAdvogado',
        'cargo'           => 'cargoNome',
        'metas'           => 'totalMetas',
        'metas_ativas'    => 'metasAtivas',
        'metas_vencidas'  => 'metasVencidas',
        'prazos'          => 'prazosProximos',
        'demandas'        => 'totalDemandas',
        'demandas_ativas' => 'demandasAtivas',
        'pastas_criadas'  => 'pastasCriadas',
    ];

    public function __construct(
        private readonly PastaRepository  $pastaRepository,
        private readonly TarefaRepository $tarefaRepository,
        private readonly UserRepository   $userRepository,
        private readonly DashboardFotoRepository $dashboardFotoRepository,
    ) {}

    /**
     * @param array<string, mixed> $filtros  filtro global do painel: data_de, data_ate,
     *                                        responsavel (userId), cargo (nome, ou CARGO_SEM
     *                                        para quem não tem cargo), busca (trecho do nome).
     *                                        Período e responsável recalculam cards + linhas;
     *                                        cargo e responsável estreitam quais colaboradores
     *                                        aparecem; a busca só esconde linhas (não mexe em
     *                                        card nenhum). Vencidas/prazos próximos seguem
     *                                        relativos a $referencia. `ordenar`/`direcao`
     *                                        ordenam a tabela pela coluna clicada no cabeçalho.
     */
    public function executar(Tenant $tenant, \DateTimeImmutable $referencia, array $filtros = []): DashboardOutput
    {
        // CARDS
        $totalMetasAtivas  = $this->tarefaRepository->countMetasAtivas($tenant, $filtros);
        $demandasUrgentes  = $this->pastaRepository->countUrgentes($tenant, $filtros);
        $global            = $this->tarefaRepository->countMetasGlobal($tenant, $filtros);
        $metaGlobalPercent = $global['total'] > 0
            ? (int) round($global['concluidas'] / $global['total'] * 100)
            : 0;

        // 7 mapas userId => count (período aplicado; vencidas/prazos são relativos à referência)
        $mTotalTarefa  = $this->tarefaRepository->countPorResponsavel($tenant, $filtros);
        $mAtivasTarefa = $this->tarefaRepository->countAtivasPorResponsavel($tenant, $filtros);
        $mVencidas     = $this->tarefaRepository->countVencidasPorResponsavel($tenant, $referencia);
        $mPrazos       = $this->tarefaRepository->countPrazosProximosPorResponsavel($tenant, $referencia);
        $mTotalPasta   = $this->pastaRepository->countPorResponsavel($tenant, $filtros);
        $mAtivasPasta  = $this->pastaRepository->countAtivasPorResponsavel($tenant, $filtros);
        // Por CRIADOR, não por responsável: mede quem abriu a pasta (uso do sistema e
        // produtividade), enquanto os dois mapas acima medem quem responde por ela.
        $mCriadasPasta = $this->pastaRepository->countCriadasPorCriador($tenant, $filtros);

        // Lista canônica: todos os colaboradores ativos do tenant
        $colaboradores = $this->userRepository->findColaboradoresAtivosPorTenant($tenant);
        $mCargo        = $colaboradores === [] ? [] : $this->userRepository->findCargoPorColaboradores($tenant);

        // Facetas de linha: responsável reduz a um colaborador; cargo reduz ao cargo escolhido.
        $respId = (int) ($filtros['responsavel'] ?? 0);
        if ($respId > 0) {
            $colaboradores = array_values(array_filter($colaboradores, static fn ($u) => $u->getId() === $respId));
        }

        $colaboradores = $this->filtrarPorCargo($colaboradores, $mCargo, trim((string) ($filtros['cargo'] ?? '')));

        // Período anterior de mesma duração (só com período completo). Roda os MESMOS count*
        // das três métricas reconstruíveis por data de criação, trocando apenas as datas.
        $periodoAnterior = $this->periodoAnterior($filtros);
        $comAnterior     = $periodoAnterior !== null;
        $mAntTarefa      = [];
        $mAntPasta       = [];
        $mAntCriadas     = [];
        if ($comAnterior && $colaboradores !== []) {
            $filtrosAnteriores = array_merge($filtros, $periodoAnterior);
            $mAntTarefa        = $this->tarefaRepository->countPorResponsavel($tenant, $filtrosAnteriores);
            $mAntPasta         = $this->pastaRepository->countPorResponsavel($tenant, $filtrosAnteriores);
            $mAntCriadas       = $this->pastaRepository->countCriadasPorCriador($tenant, $filtrosAnteriores);
        }

        // Estoque anterior (vencidas, prazos próximos): não se reconstrói do passado, então vem
        // da foto diária do dia `data_de − 1` — exatamente o fim do período anterior. Quem não
        // foi fotografado naquele dia fica sem tendência (null). Metas/Demandas ativas não
        // entram: o painel as filtra por criação no período, a foto é o estoque inteiro.
        $mEstoqueAnterior = [];
        if ($comAnterior && $colaboradores !== []) {
            $mEstoqueAnterior = $this->dashboardFotoRepository->buscarPorReferencia(
                $tenant,
                new \DateTimeImmutable($periodoAnterior['data_ate']),
                array_map(static fn ($u): int => (int) $u->getId(), $colaboradores),
            );
        }

        $mFoto = $colaboradores === [] ? [] : $this->userRepository->findFotoPorColaboradores($tenant);

        // Montar linhas — colaborador sem tarefa/pasta aparece com zeros
        $linhas = [];
        foreach ($colaboradores as $user) {
            $id       = $user->getId();
            $estoque  = $mEstoqueAnterior[$id] ?? null;
            $linhas[] = new LinhaAdvogadoDashboardOutput(
                userId:                $id,
                nomeAdvogado:          $user->getFullName(),
                cargoNome:             $mCargo[$id] ?? null,
                fotoUrl:               $mFoto[$id]  ?? null,
                totalMetas:            $mTotalTarefa[$id]  ?? 0,
                metasAtivas:           $mAtivasTarefa[$id] ?? 0,
                metasVencidas:         $mVencidas[$id]     ?? 0,
                prazosProximos:        $mPrazos[$id]       ?? 0,
                totalDemandas:         $mTotalPasta[$id]   ?? 0,
                demandasAtivas:        $mAtivasPasta[$id]  ?? 0,
                pastasCriadas:         $mCriadasPasta[$id] ?? 0,
                totalMetasAnterior:    $comAnterior ? ($mAntTarefa[$id]  ?? 0) : null,
                totalDemandasAnterior: $comAnterior ? ($mAntPasta[$id]   ?? 0) : null,
                pastasCriadasAnterior: $comAnterior ? ($mAntCriadas[$id] ?? 0) : null,
                metasVencidasAnterior:  $estoque['metas_vencidas']  ?? null,
                prazosProximosAnterior: $estoque['prazos_proximos'] ?? null,
            );
        }

        // O card "Pastas criadas" é a soma das linhas (e não um count sem agrupar): assim ele
        // respeita responsável e cargo do mesmo jeito que a tabela, e bate com a coluna.
        // Calculado ANTES da busca: buscar um nome não muda card nenhum (desenho).
        $totalPastasCriadas         = $this->somar($linhas, 'pastasCriadas');
        $totalPastasCriadasAnterior = $comAnterior ? $this->somar($linhas, 'pastasCriadasAnterior') : null;

        // Busca por nome: só esconde linhas. A linha de Total (e a tendência dela) soma o que
        // ficou visível, como no desenho.
        $linhas = $this->aplicarBusca($linhas, (string) ($filtros['busca'] ?? ''));

        $totaisAnteriores = $comAnterior
            ? [
                'metas'          => $this->somar($linhas, 'totalMetasAnterior'),
                'demandas'       => $this->somar($linhas, 'totalDemandasAnterior'),
                'pastas_criadas' => $this->somar($linhas, 'pastasCriadasAnterior'),
                // Estoque: só com foto de TODAS as linhas visíveis (ver DashboardOutput).
                'metas_vencidas'  => $this->somarSeTodos($linhas, 'metasVencidasAnterior'),
                'prazos'          => $this->somarSeTodos($linhas, 'prazosProximosAnterior'),
            ]
            : null;

        return new DashboardOutput(
            $totalMetasAtivas,
            $demandasUrgentes,
            $metaGlobalPercent,
            $this->ordenar($linhas, $filtros),
            totalPastasCriadas:         $totalPastasCriadas,
            metasConcluidas:            $global['concluidas'],
            metasTotal:                 $global['total'],
            totaisAnteriores:           $totaisAnteriores,
            totalPastasCriadasAnterior: $totalPastasCriadasAnterior,
            periodoAnterior:            $periodoAnterior,
        );
    }

    /**
     * Cargo vazio não filtra; CARGO_SEM fica com quem não tem cargo no escritório (nulo ou
     * em branco); qualquer outro valor compara pelo nome exato, como sempre foi.
     *
     * @param array<int, mixed>         $colaboradores
     * @param array<int, string|null>   $mCargo
     *
     * @return array<int, mixed>
     */
    private function filtrarPorCargo(array $colaboradores, array $mCargo, string $cargo): array
    {
        if ($cargo === '') {
            return $colaboradores;
        }

        if ($cargo === self::CARGO_SEM) {
            return array_values(array_filter(
                $colaboradores,
                static fn ($u) => trim((string) ($mCargo[$u->getId()] ?? '')) === '',
            ));
        }

        return array_values(array_filter(
            $colaboradores,
            static fn ($u) => ($mCargo[$u->getId()] ?? null) === $cargo,
        ));
    }

    /**
     * Período anterior de MESMA DURAÇÃO, imediatamente antes de `data_de`: N dias corridos
     * (inclusivos) terminando na véspera de `data_de`. Ex.: 01/03/2024 a 31/03/2024 (31 dias)
     * vira 30/01/2024 a 29/02/2024. Exige as duas datas válidas e em ordem; qualquer outra
     * coisa devolve null — sem período não há "anterior", e o painel não inventa um.
     *
     * Mesmo formato estrito ('!Y-m-d') que os repositórios usam para ler as datas, para que
     * só se compare quando o período atual de fato foi aplicado.
     *
     * @param array<string, mixed> $filtros
     *
     * @return array{data_de: string, data_ate: string}|null
     */
    private function periodoAnterior(array $filtros): ?array
    {
        $de  = $this->lerData((string) ($filtros['data_de'] ?? ''));
        $ate = $this->lerData((string) ($filtros['data_ate'] ?? ''));
        if ($de === null || $ate === null || $ate < $de) {
            return null;
        }

        $dias = (int) $de->diff($ate)->days + 1;

        return [
            'data_de'  => $de->modify(sprintf('-%d days', $dias))->format('Y-m-d'),
            'data_ate' => $de->modify('-1 day')->format('Y-m-d'),
        ];
    }

    private function lerData(string $valor): ?\DateTimeImmutable
    {
        $valor = trim($valor);
        if ($valor === '') {
            return null;
        }

        // UTC: conta de dias corridos, sem horário de verão no meio.
        $data = \DateTimeImmutable::createFromFormat('!Y-m-d', $valor, new \DateTimeZone('UTC'));
        if ($data === false || $data->format('Y-m-d') !== $valor) {
            return null;
        }

        return $data;
    }

    /**
     * Mantém só as linhas cujo nome contém o termo, sem diferenciar maiúscula nem acento
     * ("elida" acha "Élida"). Termo vazio não filtra.
     *
     * @param LinhaAdvogadoDashboardOutput[] $linhas
     *
     * @return LinhaAdvogadoDashboardOutput[]
     */
    private function aplicarBusca(array $linhas, string $busca): array
    {
        $termo = $this->normalizar(mb_substr(trim($busca), 0, self::BUSCA_MAX));
        if ($termo === '') {
            return $linhas;
        }

        return array_values(array_filter(
            $linhas,
            fn (LinhaAdvogadoDashboardOutput $l): bool => str_contains($this->normalizar($l->nomeAdvogado), $termo),
        ));
    }

    /** Minúsculas sem acento e com espaços colapsados, para comparar nomes. */
    private function normalizar(string $valor): string
    {
        $valor = strtr(mb_strtolower(trim($valor)), [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ]);

        return (string) preg_replace('/\s+/u', ' ', $valor);
    }

    /** @param LinhaAdvogadoDashboardOutput[] $linhas */
    private function somar(array $linhas, string $campo): int
    {
        return array_sum(array_map(
            static fn (LinhaAdvogadoDashboardOutput $l): int => (int) $l->$campo,
            $linhas,
        ));
    }

    /**
     * Soma do campo quando TODAS as linhas o têm; null se alguma não tem (ou se não há linha).
     * É a regra do estoque anterior: o Total só compara quando a foto cobre o grupo inteiro
     * que está na tela — somar parcial colocaria grupos diferentes lado a lado.
     *
     * @param LinhaAdvogadoDashboardOutput[] $linhas
     */
    private function somarSeTodos(array $linhas, string $campo): ?int
    {
        if ($linhas === []) {
            return null;
        }

        $total = 0;
        foreach ($linhas as $linha) {
            if ($linha->$campo === null) {
                return null;
            }
            $total += (int) $linha->$campo;
        }

        return $total;
    }

    /**
     * Ordena a tabela pela coluna clicada no cabeçalho. Sem coluna escolhida — ou com uma que não
     * existe, já que a chave chega pela URL — mantém o padrão histórico do painel: mais metas
     * primeiro. Qualquer direção diferente de `asc` é decrescente.
     *
     * @param LinhaAdvogadoDashboardOutput[] $linhas
     * @param array<string, mixed>           $filtros
     *
     * @return LinhaAdvogadoDashboardOutput[]
     */
    private function ordenar(array $linhas, array $filtros): array
    {
        $coluna = (string) ($filtros['ordenar'] ?? '');

        if (!isset(self::ORDENAVEIS[$coluna])) {
            usort($linhas, static fn (LinhaAdvogadoDashboardOutput $a, LinhaAdvogadoDashboardOutput $b): int => $b->totalMetas <=> $a->totalMetas);

            return $linhas;
        }

        $campo = self::ORDENAVEIS[$coluna];
        $asc   = ($filtros['direcao'] ?? '') === 'asc';

        // Nome e cargo ordenam como gente lê, não como bytes: o container roda com LC_COLLATE=C,
        // onde "Élida" cairia depois de "Zulmira". Mesmo caminho do MontarAtividadeEquipeUseCase.
        $collator = in_array($campo, ['nomeAdvogado', 'cargoNome'], true) ? new \Collator('pt_BR') : null;

        usort($linhas, static function (LinhaAdvogadoDashboardOutput $a, LinhaAdvogadoDashboardOutput $b) use ($campo, $asc, $collator): int {
            $cmp = $collator !== null
                ? (int) $collator->compare((string) $a->$campo, (string) $b->$campo)
                : $a->$campo <=> $b->$campo;

            return $asc ? $cmp : -$cmp;
        });

        return $linhas;
    }
}
