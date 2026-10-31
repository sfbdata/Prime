<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Pasta\Entity\PastaMensagem;

/**
 * A trilha de fases pronta para a tela, como o `navVals` do desenho 1.2.3 (E12b): estado e cor de cada fase,
 * data em que foi alcançada (dd/mm/aa), dias entre uma fase e a seguinte, "N dias (atual)" desde o último
 * andamento, registros feitos em cada fase, duração total e a dica de cada bolinha.
 */
final readonly class AndamentoDaPastaOutput
{
    /**
     * @param list<array{rotulo: string, icone: string, martelo: bool, estado: string, dia: string, intervalo: string, intervaloDica: string, registros: int, dica: string, indice: int, gap: ?array{nome: string, entre: string, exemplo: string, feita: bool}}> $fases
     * @param list<array{nome: string, indice: int, motivo: string, icone: string}> $sugestoes
     */
    public function __construct(
        public array $fases,
        public int $atual,
        public string $total,
        public string $totalDica,
        public array $sugestoes,
        public string $via,
    ) {}

    /**
     * @param array{via: string, fases: list<array{0: string, 1: string}>, atual: int, em: array<int, string>, pul: list<int>, inferida: bool, base: string} $estado
     * @param iterable<PastaMensagem> $mensagens
     * @param list<array{nome: string, indice: int, motivo: string, icone: string}> $sugestoes
     * @param array<int, string> $rotulos nome dado à etapa entre a fase de índice k e a seguinte
     */
    public static function de(array $estado, iterable $mensagens, array $sugestoes, array $rotulos = []): self
    {
        $fases = $estado['fases'];
        $fim = count($fases) - 1;
        $atual = $estado['atual'];
        $fechada = $atual === $fim;
        $pul = array_fill_keys($estado['pul'], true);
        $em = array_map(static fn (string $d): \DateTimeImmutable => new \DateTimeImmutable($d), $estado['em']);

        // Registro feito numa fase = a fase alcançada mais recente até a hora do registro (o desenho grava a fase no registro).
        $marcos = $em;
        foreach (array_keys($pul) as $k) {
            unset($marcos[$k]);
        }
        ksort($marcos);
        $porFase = [];
        foreach ($mensagens as $m) {
            if ($m->isDoSistema()) {
                continue;
            }
            $fase = null;
            foreach ($marcos as $k => $d) {
                if ($d <= $m->getCriadaEm()) {
                    $fase = $k;
                }
            }
            if ($fase !== null) {
                $porFase[$fase] = ($porFase[$fase] ?? 0) + 1;
            }
        }

        $itens = [];
        foreach ($fases as $k => [$rotulo, $icone]) {
            $reat = $rotulo === 'Reativada';
            $ehPul = isset($pul[$k]) && $k < $atual;
            $ehAtual = $k === $atual && !$fechada;
            $feito = $k < $atual || ($fechada && $k === $fim);
            $estadoK = ($fechada && $k === $fim) ? 'fim' : ($ehPul ? 'pulada' : ($reat && ($feito || $ehAtual) ? 'reativada' : ($ehAtual ? 'atual' : ($feito ? 'feita' : 'futura'))));
            $n = $porFase[$k] ?? 0;
            $dica = $rotulo
                . ($ehPul ? ' · fase pulada (não registrada); clique para voltar até ela' : '')
                . ($estado['inferida'] && $k === $atual ? ' · fase estimada pelos dados da pasta (' . $estado['base'] . '); clique em outra fase para corrigir' : '')
                . (($fechada && $k === $fim) || $ehPul ? '' : ($ehAtual ? ' · fase atual' : ($feito ? ' · concluída' : ' · ainda não realizada')))
                . ($n > 0 ? ' · ' . $n . ' registro(s) nesta fase' : '')
                . ($fechada && $k === $fim ? ' · pasta finalizada · clique para reativar' : ($ehAtual ? '' : ' · clique para marcar'));
            $itens[] = [
                'rotulo' => $rotulo, 'icone' => $icone === 'gavel' ? '' : $icone, 'martelo' => $icone === 'gavel',
                'estado' => $estadoK, 'dia' => $ehPul ? 'pulada' : (isset($em[$k]) ? $em[$k]->format('d/m/y') : ''),
                'intervalo' => '', 'intervaloDica' => '', 'registros' => $n, 'dica' => $dica, 'indice' => $k,
                'gap' => $k < $fim ? self::etapa($rotulo, $fases[$k + 1][0], $rotulos[$k] ?? '', $k < $atual) : null,
            ];
        }

        // Dias entre fases e "N dias (atual)" (desenho: `intTxt`).
        $ate = min($atual, $fim);
        $dias = static fn (\DateTimeImmutable $a, \DateTimeImmutable $b): int => max(0, (int) round(($b->getTimestamp() - $a->getTimestamp()) / 86400));
        $emDias = static fn (int $n): string => number_format($n, 0, ',', '.') . ($n === 1 ? ' dia' : ' dias');
        $ant = null;
        foreach ($itens as $k => &$item) {
            $d = $em[$k] ?? null;
            if ($k > 0 && $k <= $ate && $d !== null && $ant !== null && !isset($pul[$k])) {
                $item['intervalo'] = $emDias($dias($ant, $d));
            }
            if ($d !== null && !isset($pul[$k])) {
                $ant = $d;
            }
        }
        unset($item);
        $atualD = $em[$ate] ?? null;
        if (!$fechada && $atualD !== null && isset($itens[$ate + 1])) {
            $n = $dias($atualD, new \DateTimeImmutable());
            $itens[$ate + 1]['intervalo'] = $emDias($n) . ' (atual)';
            $itens[$ate + 1]['intervaloDica'] = 'Dias desde o último andamento (' . $atualD->format('d/m/Y') . ') até hoje: ' . $emDias($n);
        }

        $ini = $em[0] ?? null;
        $fimD = $fechada ? ($em[$fim] ?? null) : new \DateTimeImmutable();
        $total = $ini !== null && $fimD !== null ? self::duracao($dias($ini, $fimD)) : '';

        return new self($itens, $atual, $total, $fechada ? 'Duração total da pasta' : 'Tempo desde a abertura até hoje', $sugestoes, $estado['via']);
    }

    /** @return array{nome: string, entre: string, exemplo: string, feita: bool} */
    private static function etapa(string $de, string $para, string $nome, bool $feita): array
    {
        return ['nome' => $nome, 'entre' => $de . ' → ' . $para, 'exemplo' => self::exemploDeEtapa($de, $para), 'feita' => $feita];
    }

    /** Sugestão do campo ("Ex.: Triagem"), por par de fases, como o `GAP_EX` do desenho. */
    private static function exemploDeEtapa(string $de, string $para): string
    {
        $ex = self::EXEMPLOS_DE_ETAPA[$de . '>' . $para] ?? ['Diligência', 'Revisão'];

        return $ex[0] . ', ' . $ex[1];
    }

    private const EXEMPLOS_DE_ETAPA = [
        'Abertura>Documentação' => ['Triagem', 'Entrevista'], 'Documentação>Contrato' => ['Procuração', 'Proposta'],
        'Contrato>Distribuído' => ['Petição', 'Minuta'], 'Distribuído>Protocolo' => ['Revisão', 'Custas'],
        'Protocolo>Em andamento' => ['Citação', 'Emenda'], 'Em andamento>Decisão' => ['Audiência', 'Perícia'],
        'Decisão>Sentença' => ['Embargos', 'Agravo'], 'Sentença>Cumprimento' => ['Apelação', 'Trânsito'],
        'Cumprimento>Finalizada' => ['Penhora', 'Alvará'],
        'Contrato>Requerimento' => ['Documentos', 'Minuta'], 'Requerimento>Protocolo' => ['Revisão', 'Taxas'],
        'Protocolo>Em análise' => ['Agendamento', 'Perícia'], 'Em análise>Exigência' => ['Diligência', 'Ofício'],
        'Exigência>Parecer' => ['Cumprimento', 'Recurso'], 'Parecer>Cumprimento' => ['Recurso', 'Revisão'],
    ];

    private static function duracao(int $n): string
    {
        if ($n < 30) {
            return $n . ($n === 1 ? ' dia' : ' dias');
        }
        $a = intdiv($n, 365);
        $m = (int) floor(($n % 365) / 30.4);
        $partes = array_filter([$a > 0 ? $a . ($a === 1 ? ' ano' : ' anos') : '', $m > 0 ? $m . ($m === 1 ? ' mês' : ' meses') : '']);

        return $partes !== [] ? implode(' e ', $partes) : $n . ' dias';
    }
}
