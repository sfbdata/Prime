<?php

declare(strict_types=1);

namespace App\Dashboard\UseCase;

use App\Dashboard\DTO\HistoricoDoCardOutput;
use App\Dashboard\Repository\HistoricoDoDashboardRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserRepository;

/**
 * Janela "Histórico" de um card do Dashboard (botão direito no card — DASH-017).
 *
 * **Quem:** o gestor que vê o Dashboard (módulo `bi`; a guarda é do controller).
 *
 * **O quê:** a série de 12 meses do número do card, com o seletor de ano do desenho (ano atual e
 * os dois anteriores) e "Todos" (uma barra por ano). Para cada opção: barras (mês corrente na cor
 * do card, melhor mês em dourado, meses que ainda não chegaram vazios) e os 3 indicadores — Média,
 * Melhor e a comparação com o mesmo intervalo do ano anterior.
 *
 * **Só o que se reconstrói:** Pastas criadas sai das datas de abertura, com o MESMO universo do
 * card (colaboradores ativos do escritório, estreitados pelo responsável e pelo cargo do painel,
 * sem lápide). Metas ativas, Demandas urgentes e Meta global são retratos do momento: o valor de
 * um mês passado não se recupera das datas, e a foto diária (`dashboard_foto`) ainda não roda
 * em produção — a janela diz isso e não mostra número nenhum.
 *
 * **Isolamento:** o responsável vem da URL, mas só vale dentro do universo do escritório
 * corrente: id de outro escritório dá universo vazio (série de zeros), sem revelar nome.
 *
 * **Erros:** não lança. Card desconhecido não chega aqui (a rota restringe).
 */
final class ObterHistoricoDoCardUseCase
{
    public const CARDS = ['pastas', 'metas', 'urgentes', 'global'];

    private const CONFIG = [
        'pastas'   => ['titulo' => 'Pastas criadas', 'icone' => 'bi-folder-plus'],
        'metas'    => ['titulo' => 'Metas ativas', 'icone' => 'bi-check2-square'],
        'urgentes' => ['titulo' => 'Demandas urgentes', 'icone' => 'bi-flag-fill'],
        'global'   => ['titulo' => 'Meta global batida', 'icone' => 'bi-bullseye'],
    ];

    /** Rodapé do desenho para Pastas criadas (HIST_CFG.c.nota). */
    private const NOTA_PASTAS = 'Pastas abertas em cada mês. No ano, soma dos meses.';

    /** Texto honesto dos cards sem série reconstruível. */
    private const NOTA_SEM_FOTO = '%s é um retrato do momento: o valor de um mês que já passou não se recupera pelas datas gravadas. '
        . 'O histórico depende da foto diária do painel, que ainda não está ativada. Por isso nenhum número é mostrado aqui.';

    private const SUBTITULO = 'Histórico mensal';

    private const INICIAIS  = ['J', 'F', 'M', 'A', 'M', 'J', 'J', 'A', 'S', 'O', 'N', 'D'];
    private const MES_CURTO = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

    /** Anos no seletor (desenho: 2026 · 2025 · 2024 · Todos). */
    private const ANOS_NO_SELETOR = 3;

    /** Altura útil das barras (px): a maior chega a 80; vazia tem 3; com valor, no mínimo 4. */
    private const ALTURA_MAX   = 80;
    private const ALTURA_VAZIA = 3;
    private const ALTURA_MIN   = 4;

    public function __construct(
        private readonly HistoricoDoDashboardRepository $historicoRepository,
        private readonly UserRepository $userRepository,
    ) {
    }

    /**
     * @param array<string, mixed> $filtros responsavel (userId), cargo (nome ou CARGO_SEM) — os do painel
     */
    public function executar(Tenant $tenant, string $card, \DateTimeImmutable $referencia, array $filtros = []): HistoricoDoCardOutput
    {
        $config = self::CONFIG[$card] ?? self::CONFIG['pastas'];

        if ($card !== 'pastas') {
            return new HistoricoDoCardOutput(
                card:       $card,
                titulo:     $config['titulo'],
                subtitulo:  self::SUBTITULO,
                icone:      $config['icone'],
                disponivel: false,
                nota:       sprintf(self::NOTA_SEM_FOTO, $config['titulo']),
                inicial:    null,
                opcoes:     [],
            );
        }

        [$universo, $recorte] = $this->universo($tenant, $filtros);

        $anoAtual = (int) $referencia->format('Y');
        $mesAtual = (int) $referencia->format('n');
        $primeiro = $anoAtual - self::ANOS_NO_SELETOR; // um a mais: a comparação do ano mais antigo

        $porMes = $this->historicoRepository->contarPastasCriadasPorMes(
            $tenant,
            array_map(static fn (User $u): int => (int) $u->getId(), $universo),
            new \DateTimeImmutable(sprintf('%d-01-01 00:00:00', $primeiro)),
            new \DateTimeImmutable(sprintf('%d-12-31 23:59:59', $anoAtual)),
        );

        $series = [];
        for ($ano = $primeiro; $ano <= $anoAtual; ++$ano) {
            $series[$ano] = $this->serieDoAno($porMes, $ano, $anoAtual, $mesAtual);
        }

        $opcoes = [];
        for ($ano = $anoAtual; $ano > $anoAtual - self::ANOS_NO_SELETOR; --$ano) {
            $opcoes[] = $this->opcaoDoAno($ano, $series[$ano], $series[$ano - 1]);
        }
        $opcoes[] = $this->opcaoTodos(array_slice($series, 1, null, true));

        return new HistoricoDoCardOutput(
            card:       'pastas',
            titulo:     $config['titulo'],
            subtitulo:  $recorte === '' ? self::SUBTITULO : self::SUBTITULO . ' · ' . $recorte,
            icone:      $config['icone'],
            disponivel: true,
            nota:       self::NOTA_PASTAS,
            inicial:    (string) $anoAtual,
            opcoes:     $opcoes,
        );
    }

    /**
     * O universo do card (ObterDadosDashboardUseCase): colaboradores ativos do escritório,
     * reduzidos ao responsável e ao cargo do painel — mesma regra, inclusive CARGO_SEM.
     *
     * @param array<string, mixed> $filtros
     *
     * @return array{0: list<User>, 1: string} universo e o rótulo do recorte ('' = equipe toda)
     */
    private function universo(Tenant $tenant, array $filtros): array
    {
        $ativos = $this->userRepository->findColaboradoresAtivosPorTenant($tenant);
        $respId = (int) ($filtros['responsavel'] ?? 0);
        $cargo  = trim((string) ($filtros['cargo'] ?? ''));

        $universo = $ativos;
        $recorte  = '';
        if ($respId > 0) {
            $universo = array_values(array_filter($ativos, static fn (User $u): bool => $u->getId() === $respId));
            // Nome só sai do escritório corrente: id alheio não revela ninguém.
            $recorte = $universo !== [] ? $universo[0]->getFullName() : 'colaborador não encontrado';
        }

        if ($cargo !== '') {
            $mCargo   = $this->userRepository->findCargoPorColaboradores($tenant);
            $universo = array_values(array_filter(
                $universo,
                static fn (User $u): bool => $cargo === ObterDadosDashboardUseCase::CARGO_SEM
                    ? trim((string) ($mCargo[$u->getId()] ?? '')) === ''
                    : ($mCargo[$u->getId()] ?? null) === $cargo,
            ));
            if ($respId <= 0) {
                $recorte = $cargo === ObterDadosDashboardUseCase::CARGO_SEM ? 'Sem cargo' : $cargo;
            }
        }

        return [array_values($universo), $recorte];
    }

    /**
     * 12 valores do ano; null nos meses que ainda não chegaram (o mês corrente conta o parcial).
     *
     * @param array<string, int> $porMes
     *
     * @return list<int|null>
     */
    private function serieDoAno(array $porMes, int $ano, int $anoAtual, int $mesAtual): array
    {
        $serie = [];
        for ($mes = 1; $mes <= 12; ++$mes) {
            $serie[] = ($ano === $anoAtual && $mes > $mesAtual)
                ? null
                : ($porMes[sprintf('%04d-%02d', $ano, $mes)] ?? 0);
        }

        return $serie;
    }

    /**
     * @param list<int|null> $serie
     * @param list<int|null> $anterior a série do ano anterior (todos os meses fechados)
     *
     * @return array<string, mixed>
     */
    private function opcaoDoAno(int $ano, array $serie, array $anterior): array
    {
        $titulos = [];
        foreach ($serie as $i => $v) {
            $titulos[] = self::MES_CURTO[$i] . '/' . $ano . ': ' . ($v === null ? 'ainda não fechou' : (string) $v);
        }

        $barras = $this->barras($serie, self::INICIAIS, $titulos);
        $vals   = array_values(array_filter($serie, static fn (?int $v): bool => $v !== null));
        $max    = $vals === [] ? 0 : max($vals);
        $idx    = $max > 0 ? array_search($max, $serie, true) : false;
        $nMeses = count($vals);

        // Mesmo intervalo do ano anterior: os mesmos N primeiros meses.
        $atual = array_sum($vals);
        $antes = array_sum(array_slice(array_map(static fn (?int $v): int => (int) $v, $anterior), 0, $nMeses));
        if ($antes > 0) {
            $p    = (int) round(($atual - $antes) / $antes * 100);
            $comp = [
                'rotulo' => 'vs ' . ($ano - 1),
                'valor'  => $this->sinal($p) . abs($p) . '%',
                'sub'    => $antes . ' → ' . $atual,
                'tom'    => $p > 0 ? 'bom' : ($p < 0 ? 'ruim' : 'igual'),
            ];
        } else {
            // Sem pasta no mesmo intervalo do ano anterior não há percentual a calcular.
            $comp = ['rotulo' => 'vs ' . ($ano - 1), 'valor' => 'N/D', 'sub' => $antes . ' → ' . $atual, 'tom' => 'igual'];
        }

        return [
            'chave'  => (string) $ano,
            'rotulo' => (string) $ano,
            'barras' => $barras,
            'stats'  => [
                [
                    'rotulo' => 'Média/mês',
                    'valor'  => $this->decimal($nMeses > 0 ? $atual / $nMeses : 0.0),
                    'sub'    => $nMeses === 1 ? '1 mês' : $nMeses . ' meses',
                    'tom'    => 'neutro',
                ],
                $idx === false
                    ? ['rotulo' => 'Melhor', 'valor' => '0', 'sub' => 'Sem dado', 'tom' => 'neutro']
                    : ['rotulo' => 'Melhor', 'valor' => (string) $max, 'sub' => self::MES_CURTO[(int) $idx] . '/' . $ano, 'tom' => 'ouro'],
                $comp,
            ],
        ];
    }

    /**
     * "Todos": uma barra por ano do seletor (do mais antigo ao atual), soma dos meses.
     *
     * @param array<int, list<int|null>> $series ano => série
     *
     * @return array<string, mixed>
     */
    private function opcaoTodos(array $series): array
    {
        $anos    = array_keys($series);
        $somas   = array_values(array_map(
            static fn (array $s): int => (int) array_sum(array_filter($s, static fn (?int $v): bool => $v !== null)),
            $series,
        ));
        $titulos = array_map(static fn (int $a, int $s): string => $a . ': ' . $s . ' no ano', $anos, $somas);
        $rotulos = array_map(static fn (int $a): string => (string) $a, $anos);

        $max = $somas === [] ? 0 : max($somas);
        $idx = $max > 0 ? array_search($max, $somas, true) : false;

        $primeiro = $somas[0] ?? 0;
        $ultimo   = $somas[count($somas) - 1] ?? 0;
        if ($primeiro > 0) {
            $p        = (int) round(($ultimo - $primeiro) / $primeiro * 100);
            $variacao = $this->sinal($p) . abs($p) . '%';
        } else {
            $variacao = 'N/D';
        }

        return [
            'chave'  => 'todos',
            'rotulo' => 'Todos',
            'barras' => $this->barras($somas, $rotulos, $titulos),
            'stats'  => [
                [
                    'rotulo' => 'Média/ano',
                    'valor'  => $this->decimal($somas === [] ? 0.0 : array_sum($somas) / count($somas)),
                    'sub'    => count($somas) . ' anos',
                    'tom'    => 'neutro',
                ],
                $idx === false
                    ? ['rotulo' => 'Melhor', 'valor' => '0', 'sub' => 'Sem dado', 'tom' => 'neutro']
                    : ['rotulo' => 'Melhor', 'valor' => (string) $max, 'sub' => $rotulos[(int) $idx], 'tom' => 'ouro'],
                [
                    'rotulo' => 'Variação',
                    'valor'  => $variacao,
                    'sub'    => ($rotulos[0] ?? '') . ' → ' . ($rotulos[count($rotulos) - 1] ?? ''),
                    'tom'    => 'igual',
                ],
            ],
        ];
    }

    /**
     * Barras do desenho: a maior chega a ALTURA_MAX; o melhor (maior, se > 0) é dourado; a última
     * com valor é a "atual" (na cor do card). Só essas duas mostram o número em cima.
     *
     * @param list<int|null> $serie
     * @param list<string>   $rotulos
     * @param list<string>   $titulos
     *
     * @return list<array<string, mixed>>
     */
    private function barras(array $serie, array $rotulos, array $titulos): array
    {
        $vals   = array_values(array_filter($serie, static fn (?int $v): bool => $v !== null));
        $max    = $vals === [] ? 0 : max($vals);
        $escala = max($max, 1);
        $melhor = $max > 0 ? array_search($max, $serie, true) : false;
        $ultimo = null;
        foreach ($serie as $i => $v) {
            if ($v !== null) {
                $ultimo = $i;
            }
        }

        $barras = [];
        foreach ($serie as $i => $v) {
            $eMelhor  = $melhor !== false && $i === $melhor;
            $eAtual   = $i === $ultimo;
            $barras[] = [
                'rotulo'       => $rotulos[$i],
                'titulo'       => $titulos[$i],
                'valor'        => $v,
                'altura'       => $v === null ? self::ALTURA_VAZIA : max(self::ALTURA_MIN, (int) round($v / $escala * self::ALTURA_MAX)),
                'melhor'       => $eMelhor,
                'atual'        => $eAtual,
                'mostrarValor' => $v !== null && ($eMelhor || $eAtual),
            ];
        }

        return $barras;
    }

    /** "+" para alta, "−" (sinal de menos tipográfico, como no desenho) para queda. */
    private function sinal(int $p): string
    {
        return $p > 0 ? '+' : ($p < 0 ? '−' : '');
    }

    /** Uma casa, vírgula decimal, sem ",0" (desenho: 4,6 · 5). */
    private function decimal(float $valor): string
    {
        $txt = number_format(round($valor, 1), 1, ',', '');

        return str_ends_with($txt, ',0') ? substr($txt, 0, -2) : $txt;
    }
}
