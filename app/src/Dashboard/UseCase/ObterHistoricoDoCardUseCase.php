<?php

declare(strict_types=1);

namespace App\Dashboard\UseCase;

use App\Dashboard\DTO\HistoricoDoCardOutput;
use App\Dashboard\Repository\DashboardFotoRepository;
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
 * **Pastas criadas** sai das datas de abertura, com o MESMO universo do card (colaboradores
 * ativos do escritório, estreitados pelo responsável e pelo cargo do painel, sem lápide).
 *
 * **Metas ativas, Demandas urgentes e Meta global** são retratos do momento: o valor de um mês
 * passado não se recupera das datas. A série vem da foto diária (`dashboard_foto`, PR-F): o retrato
 * de cada mês é a soma, sobre o universo do card, das fotos do último dia do mês que tem foto (meta
 * com mais de um responsável conta para cada um). Mês sem foto fica vazio ("sem histórico"); nunca
 * há backfill nem número estimado, e a série só vale daqui para a frente. Com pasta oculta pelo
 * cadeado para quem olha, a foto (do escritório inteiro) não serve e a janela diz isso.
 *
 * **Isolamento:** o responsável vem da URL, mas só vale dentro do universo do escritório
 * corrente: id de outro escritório dá universo vazio (série de zeros), sem revelar nome.
 *
 * **Erros:** não lança. Card desconhecido não chega aqui (a rota restringe).
 */
final class ObterHistoricoDoCardUseCase
{
    public const CARDS = ['pastas', 'metas', 'urgentes', 'global'];

    /** tipo: soma (contagem; o ano soma os meses), estoque (retrato; o ano é a média) ou pct; melhor: max, min ou null (sem melhor, mostra o pico). */
    private const CONFIG = [
        'pastas'   => ['titulo' => 'Pastas criadas', 'icone' => 'bi-folder-plus', 'tipo' => 'soma', 'melhor' => 'max'],
        'metas'    => ['titulo' => 'Metas ativas', 'icone' => 'bi-check2-square', 'tipo' => 'estoque', 'melhor' => null],
        'urgentes' => ['titulo' => 'Demandas urgentes', 'icone' => 'bi-flag-fill', 'tipo' => 'estoque', 'melhor' => 'min'],
        'global'   => ['titulo' => 'Meta global batida', 'icone' => 'bi-bullseye', 'tipo' => 'pct', 'melhor' => 'max'],
    ];

    /** Rodapé do desenho de cada card (HIST_CFG.nota), com a ressalva da foto onde ela vale. */
    private const NOTAS = [
        'pastas'   => 'Pastas abertas em cada mês. No ano, soma dos meses.',
        'metas'    => 'Metas em aberto na última foto diária de cada mês. Fila: subir ou cair não é bom nem ruim sozinho. Mês sem foto aparece vazio.',
        'urgentes' => 'Pastas com prioridade Urgente na última foto diária de cada mês. Menos é melhor. Mês sem foto aparece vazio.',
        'global'   => 'Percentual de metas concluídas sobre o total criado, na última foto diária de cada mês. Mês sem foto aparece vazio.',
    ];

    /** Nenhuma foto no período: a série só existe daqui para a frente. */
    private const NOTA_SEM_HISTORICO = '%s é um retrato do momento e o histórico começa a ser guardado pela foto diária do painel. '
        . 'Ainda não há foto neste período, por isso nenhum número é mostrado aqui (sem histórico).';

    /** A foto é do escritório inteiro: para quem tem pasta oculta pelo cadeado ela revelaria o que está oculto. */
    private const NOTA_CADEADO = '%s depende da foto diária do escritório inteiro, que inclui pastas que o seu acesso não mostra. '
        . 'Por isso nenhum número é mostrado aqui.';

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
        private readonly DashboardFotoRepository $fotoRepository,
    ) {
    }

    /**
     * @param array<string, mixed> $filtros responsavel (userId), cargo (nome ou CARGO_SEM) — os do painel
     * @param list<int>            $pastasOcultas pastas ocultas pelo cadeado para quem olha: não contam, como no card
     */
    public function executar(Tenant $tenant, string $card, \DateTimeImmutable $referencia, array $filtros = [], array $pastasOcultas = []): HistoricoDoCardOutput
    {
        $config = self::CONFIG[$card] ?? self::CONFIG['pastas'];
        $card   = isset(self::CONFIG[$card]) ? $card : 'pastas';

        if ($card !== 'pastas' && $pastasOcultas !== []) {
            return $this->indisponivel($card, $config, sprintf(self::NOTA_CADEADO, $config['titulo']));
        }

        [$universo, $recorte] = $this->universo($tenant, $filtros);

        $anoAtual = (int) $referencia->format('Y');
        $primeiro = $anoAtual - self::ANOS_NO_SELETOR; // um a mais: a comparação do ano mais antigo
        $ids      = array_map(static fn (User $u): int => (int) $u->getId(), $universo);
        $de       = new \DateTimeImmutable(sprintf('%d-01-01 00:00:00', $primeiro));
        $ate      = new \DateTimeImmutable(sprintf('%d-12-31 23:59:59', $anoAtual));

        $porMes = $card === 'pastas'
            ? $this->historicoRepository->contarPastasCriadasPorMes($tenant, $ids, $de, $ate, $pastasOcultas)
            : $this->retratosDoCard($tenant, $card, $ids, $de, $ate);

        $opcoes = $this->opcoesDoCard($porMes, $card, $config, $anoAtual, (int) $referencia->format('n'));
        if ($opcoes === null) {
            return $this->indisponivel($card, $config, sprintf(self::NOTA_SEM_HISTORICO, $config['titulo']));
        }

        return new HistoricoDoCardOutput(
            card:       $card,
            titulo:     $config['titulo'],
            subtitulo:  $recorte === '' ? self::SUBTITULO : self::SUBTITULO . ' · ' . $recorte,
            icone:      $config['icone'],
            disponivel: true,
            nota:       self::NOTAS[$card],
            inicial:    (string) $anoAtual,
            opcoes:     $opcoes,
        );
    }

    /**
     * As opções do seletor (anos e "Todos"); null quando um card de foto não tem nenhum mês com valor.
     *
     * @param array<string, int>                                                   $porMes
     * @param array{titulo: string, icone: string, tipo: string, melhor: ?string} $config
     *
     * @return list<array<string, mixed>>|null
     */
    private function opcoesDoCard(array $porMes, string $card, array $config, int $anoAtual, int $mesAtual): ?array
    {
        $primeiro = $anoAtual - self::ANOS_NO_SELETOR;
        $series   = [];
        for ($ano = $primeiro; $ano <= $anoAtual; ++$ano) {
            $series[$ano] = $this->serieDoAno($porMes, $ano, $anoAtual, $mesAtual, $card === 'pastas');
        }

        if ($card !== 'pastas' && array_filter($series, static fn (array $s): bool => array_filter($s, static fn (?int $v): bool => $v !== null) !== []) === []) {
            return null;
        }

        $opcoes = [];
        for ($ano = $anoAtual; $ano > $anoAtual - self::ANOS_NO_SELETOR; --$ano) {
            $opcoes[] = $this->opcaoDoAno($ano, $series[$ano], $series[$ano - 1], $config, $anoAtual, $mesAtual);
        }
        $opcoes[] = $this->opcaoTodos(array_slice($series, 1, null, true), $config);

        return $opcoes;
    }

    /** @param array{titulo: string, icone: string, tipo: string, melhor: ?string} $config */
    private function indisponivel(string $card, array $config, string $nota): HistoricoDoCardOutput
    {
        return new HistoricoDoCardOutput(
            card:       $card,
            titulo:     $config['titulo'],
            subtitulo:  self::SUBTITULO,
            icone:      $config['icone'],
            disponivel: false,
            nota:       $nota,
            inicial:    null,
            opcoes:     [],
        );
    }

    /**
     * O retrato de cada mês, 'YYYY-MM' => valor: só os meses com foto (os outros nem entram).
     * Metas ativas e Urgentes: a soma da coluna; Meta global: o % de concluídas (total − ativas) sobre
     * o total, e mês sem meta no total não tem percentual.
     *
     * @param list<int> $ids
     *
     * @return array<string, int>
     */
    private function retratosDoCard(Tenant $tenant, string $card, array $ids, \DateTimeImmutable $de, \DateTimeImmutable $ate): array
    {
        $coluna = ['metas' => 'metas_ativas', 'urgentes' => 'pastas_urgentes', 'global' => 'metas_total'][$card];
        $mapa   = [];
        foreach ($this->fotoRepository->retratoPorMes($tenant, $ids, $coluna, $de, $ate) as $mes => $r) {
            if ($card !== 'global') {
                $mapa[$mes] = $r['valor'];
            } elseif ($r['valor'] > 0) {
                $mapa[$mes] = (int) round(max(0, $r['valor'] - $r['ativas']) / $r['valor'] * 100);
            }
        }

        return $mapa;
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
     * Contagem sem registro é zero ($faltaEhZero); retrato sem foto fica null ("sem histórico").
     *
     * @param array<string, int> $porMes
     *
     * @return list<int|null>
     */
    private function serieDoAno(array $porMes, int $ano, int $anoAtual, int $mesAtual, bool $faltaEhZero): array
    {
        $serie = [];
        for ($mes = 1; $mes <= 12; ++$mes) {
            $serie[] = ($ano === $anoAtual && $mes > $mesAtual)
                ? null
                : ($porMes[sprintf('%04d-%02d', $ano, $mes)] ?? ($faltaEhZero ? 0 : null));
        }

        return $serie;
    }

    /**
     * @param list<int|null> $serie
     * @param list<int|null> $anterior a série do ano anterior (todos os meses fechados)
     * @param array{titulo: string, icone: string, tipo: string, melhor: ?string} $config
     *
     * @return array<string, mixed>
     */
    private function opcaoDoAno(int $ano, array $serie, array $anterior, array $config, int $anoAtual, int $mesAtual): array
    {
        $titulos = [];
        foreach ($serie as $i => $v) {
            $titulos[] = self::MES_CURTO[$i] . '/' . $ano . ': ' . ($v === null ? ($ano === $anoAtual && $i + 1 > $mesAtual ? 'ainda não fechou' : 'sem histórico') : $this->formatar($v, $config['tipo']));
        }

        $barras = $this->barras($serie, self::INICIAIS, $titulos, $config);
        $vals   = array_values(array_filter($serie, static fn (?int $v): bool => $v !== null));
        $nMeses = count($vals);
        $alvo   = $this->indiceDoMelhor($serie, $config['melhor']);

        return [
            'chave'  => (string) $ano,
            'rotulo' => (string) $ano,
            'barras' => $barras,
            'stats'  => [
                [
                    'rotulo' => $config['tipo'] === 'soma' ? 'Média/mês' : 'Média',
                    'valor'  => $this->decimal($nMeses > 0 ? array_sum($vals) / $nMeses : 0.0, $config['tipo']),
                    'sub'    => $nMeses === 1 ? '1 mês' : $nMeses . ' meses',
                    'tom'    => 'neutro',
                ],
                $this->statMelhor($serie, $alvo, (string) $ano, true, $config),
                $this->comparacao($ano, $serie, $anterior, $nMeses, $config),
            ],
        ];
    }

    /**
     * Comparação com o mesmo intervalo do ano anterior (os mesmos N primeiros meses).
     *
     * @param list<int|null> $serie
     * @param list<int|null> $anterior
     * @param array{titulo: string, icone: string, tipo: string, melhor: ?string} $config
     *
     * @return array{rotulo: string, valor: string, sub: string, tom: string}
     */
    private function comparacao(int $ano, array $serie, array $anterior, int $nMeses, array $config): array
    {
        $rotulo = 'vs ' . ($ano - 1);
        if ($config['tipo'] !== 'soma') {
            // Retrato: só os meses com foto nos DOIS anos (a série começa no meio do ano, então os N primeiros meses não servem).
            $comuns   = array_keys(array_filter($serie, static fn (?int $v, int $i): bool => $v !== null && ($anterior[$i] ?? null) !== null, ARRAY_FILTER_USE_BOTH));
            $serie    = array_map(static fn (int $i): ?int => $serie[$i], $comuns);
            $anterior = array_map(static fn (int $i): ?int => $anterior[$i], $comuns);
            $nMeses   = count($comuns);
        }
        $atual = $this->agregar($serie, $config['tipo']);
        $antes = $this->agregar(array_slice($anterior, 0, $nMeses), $config['tipo']);

        if ($config['tipo'] === 'soma') {
            if ($antes === null || $antes <= 0) {
                // Sem registro no mesmo intervalo do ano anterior não há percentual a calcular.
                return ['rotulo' => $rotulo, 'valor' => 'N/D', 'sub' => (int) $antes . ' → ' . (int) $atual, 'tom' => 'igual'];
            }
        } elseif (!$antes || $atual === null) {
            // Retrato: sem foto no ano anterior (ou em nenhum mês deste) não há com o que comparar.
            return ['rotulo' => $rotulo, 'valor' => 'N/D', 'sub' => 'sem histórico', 'tom' => 'igual'];
        }

        $subirBom = $config['melhor'] === 'max' ? true : ($config['melhor'] === 'min' ? false : null);
        if ($config['tipo'] === 'pct') {
            $dif = (int) $atual - $antes;

            return ['rotulo' => $rotulo, 'valor' => $this->sinal($dif) . abs($dif) . ' p.p.', 'sub' => $antes . '% → ' . $atual . '%', 'tom' => $this->tomDaComparacao($dif, $subirBom)];
        }

        $p = (int) round(((int) $atual - $antes) / $antes * 100);

        return ['rotulo' => $rotulo, 'valor' => $this->sinal($p) . abs($p) . '%', 'sub' => $antes . ' → ' . (int) $atual, 'tom' => $this->tomDaComparacao($p, $subirBom)];
    }

    /** Verde/vermelho pelo sentido do card; sem sentido (fila) ou sem variação, neutro. */
    private function tomDaComparacao(int $variacao, ?bool $subirBom): string
    {
        if ($variacao === 0 || $subirBom === null) {
            return 'igual';
        }

        return ($variacao > 0) === $subirBom ? 'bom' : 'ruim';
    }

    /**
     * "Todos": uma barra por ano do seletor (do mais antigo ao atual): soma dos meses na contagem,
     * média do ano no retrato; ano sem nenhum mês com valor fica vazio.
     *
     * @param array<int, list<int|null>> $series ano => série
     * @param array{titulo: string, icone: string, tipo: string, melhor: ?string} $config
     *
     * @return array<string, mixed>
     */
    private function opcaoTodos(array $series, array $config): array
    {
        $anos    = array_keys($series);
        $somas   = array_values(array_map(fn (array $s): ?int => $this->agregar($s, $config['tipo']), $series));
        $titulos = array_map(
            fn (int $a, ?int $s): string => $a . ': ' . ($s === null ? 'sem histórico' : $this->formatar($s, $config['tipo']) . ($config['tipo'] === 'soma' ? ' no ano' : ' (média do ano)')),
            $anos,
            $somas,
        );
        $rotulos = array_map(static fn (int $a): string => (string) $a, $anos);
        $vals    = array_values(array_filter($somas, static fn (?int $v): bool => $v !== null));
        $alvo    = $this->indiceDoMelhor($somas, $config['melhor']);

        $primeiro = $somas[0] ?? null;
        $ultimo   = $somas[count($somas) - 1] ?? null;
        $pVar     = $primeiro ? (int) round(((int) $ultimo - $primeiro) / $primeiro * 100) : 0;
        $variacao = $primeiro ? $this->sinal($pVar) . abs($pVar) . '%' : 'N/D';

        return [
            'chave'  => 'todos',
            'rotulo' => 'Todos',
            'barras' => $this->barras($somas, $rotulos, $titulos, $config),
            'stats'  => [
                [
                    'rotulo' => $config['tipo'] === 'soma' ? 'Média/ano' : 'Média',
                    'valor'  => $this->decimal($vals === [] ? 0.0 : array_sum($vals) / count($vals), $config['tipo']),
                    'sub'    => count($vals) . ' anos',
                    'tom'    => 'neutro',
                ],
                $this->statMelhor($somas, $alvo, '', false, $config, $rotulos),
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
     * O stat do meio: "Melhor" (ouro) quando o card tem sentido; "Melhor (menor)" se menos é melhor;
     * "Pico" (neutro) na fila. Sem dado, "Sem dado".
     *
     * @param list<int|null>       $serie
     * @param array{titulo: string, icone: string, tipo: string, melhor: ?string} $config
     * @param list<string>|null    $rotulos rótulos de ano (no "Todos")
     *
     * @return array{rotulo: string, valor: string, sub: string, tom: string}
     */
    private function statMelhor(array $serie, int|false $alvo, string $ano, bool $porMes, array $config, ?array $rotulos = null): array
    {
        $rotulo = $config['melhor'] === 'min' ? 'Melhor (menor)' : ($config['melhor'] === null ? 'Pico' : 'Melhor');
        $tom    = $config['melhor'] === null ? 'neutro' : 'ouro';
        if ($alvo === false) {
            return ['rotulo' => $rotulo, 'valor' => $config['tipo'] === 'pct' ? '0%' : '0', 'sub' => 'Sem dado', 'tom' => 'neutro'];
        }

        return [
            'rotulo' => $rotulo,
            'valor'  => $this->formatar((int) $serie[$alvo], $config['tipo']),
            'sub'    => $porMes ? self::MES_CURTO[$alvo] . '/' . $ano : ($rotulos[$alvo] ?? ''),
            'tom'    => $tom,
        ];
    }

    /**
     * Índice do melhor valor (para o destaque e o stat): maior (precisa ser > 0), menor, ou, na fila
     * (sem melhor), o pico, que não ganha destaque. False sem dado.
     *
     * @param list<int|null> $serie
     */
    private function indiceDoMelhor(array $serie, ?string $melhor): int|false
    {
        $vals = array_values(array_filter($serie, static fn (?int $v): bool => $v !== null));
        if ($vals === []) {
            return false;
        }
        if ($melhor === 'min') {
            return array_search(min($vals), $serie, true);
        }

        return max($vals) > 0 ? array_search(max($vals), $serie, true) : false;
    }

    /**
     * Soma (contagem) ou média arredondada (retrato) dos valores que existem; null se nenhum.
     *
     * @param list<int|null> $serie
     */
    private function agregar(array $serie, string $tipo): ?int
    {
        $vals = array_values(array_filter($serie, static fn (?int $v): bool => $v !== null));
        if ($vals === []) {
            return null;
        }

        return $tipo === 'soma' ? (int) array_sum($vals) : (int) round(array_sum($vals) / count($vals));
    }

    private function formatar(int $valor, string $tipo): string
    {
        return $tipo === 'pct' ? $valor . '%' : (string) $valor;
    }

    /**
     * Barras do desenho: a maior chega a ALTURA_MAX; o melhor (quando o card tem sentido) é dourado;
     * a última com valor é a "atual" (na cor do card). Só essas duas mostram o número em cima.
     * Sem valor (null), barra vazia.
     *
     * @param list<int|null> $serie
     * @param list<string>   $rotulos
     * @param list<string>   $titulos
     * @param array{titulo: string, icone: string, tipo: string, melhor: ?string} $config
     *
     * @return list<array<string, mixed>>
     */
    private function barras(array $serie, array $rotulos, array $titulos, array $config): array
    {
        $vals   = array_values(array_filter($serie, static fn (?int $v): bool => $v !== null));
        $max    = $vals === [] ? 0 : max($vals);
        $escala = max($max, 1);
        $melhor = $config['melhor'] === null ? false : $this->indiceDoMelhor($serie, $config['melhor']);
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
                'texto'        => $v === null ? '' : $this->formatar($v, $config['tipo']),
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
    private function decimal(float $valor, string $tipo = 'soma'): string
    {
        $txt = number_format(round($valor, 1), 1, ',', '');
        $txt = str_ends_with($txt, ',0') ? substr($txt, 0, -2) : $txt;

        return $tipo === 'pct' ? $txt . '%' : $txt;
    }
}
