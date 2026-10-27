<?php

declare(strict_types=1);

namespace App\Master\Service;

use App\Master\Entity\ConvenioDaUf;
use App\Master\Entity\LancamentoDeCusto;
use App\Master\Enum\CategoriaDeCusto;

/**
 * As contas da seção Custos do Master (desenho 05 Perfis 1.2, `csKpis`, `csCat`, `csMarg`, `csProj`; spec
 * docs/specs/master-custos.md D3 a D5). Pura: recebe os lançamentos do mês, as contas e o catálogo de planos, devolve
 * números em centavos. Quem formata é a tela.
 *
 * - Receita recorrente = por conta ATIVA, o preço do plano mais o valor do convênio da UF quando o modo é `dif` ou
 *   `sem` (como no desenho). É a receita de hoje, não a do mês escolhido (D3).
 * - Custo por GB = (Armazenamento + Backups) / GB armazenados pelas contas ativas; sem GB ou sem lançamento, nulo.
 * - Custo do plano = custo por GB x cota do plano; margem = preço - custo. Plano sem cota ou sem custo por GB: nulos.
 */
final class ApuracaoDeCustos
{
    public const BYTES_POR_GB = 1073741824;
    public const PROJECOES_EM_MESES = [3, 6, 12];
    private const SITUACAO_ATIVA = 'Ativa';

    /**
     * @param list<LancamentoDeCusto>                                                                                                        $lancamentos
     * @param list<array{situacao: string, usuarios: int, bytes: int, espaco: array{planoCodigo: string}, convenio: array{modo: string, valor: float}}> $contas
     * @param list<array{codigo: string, nome: string, cotaGb: int, precoCentavos: int, ativo: bool}>                                        $planos
     *
     * @return array<string, mixed>
     */
    public static function apurar(string $mes, array $lancamentos, array $contas, array $planos): array
    {
        $ativas = array_values(array_filter($contas, static fn (array $c): bool => $c['situacao'] === self::SITUACAO_ATIVA));
        $precos = array_column($planos, 'precoCentavos', 'codigo');

        $custo = array_sum(array_map(static fn (LancamentoDeCusto $l): int => $l->getValorCentavos(), $lancamentos));
        $receita = 0;
        $usuarios = 0;
        $bytes = 0;
        $contasPorPlano = [];
        foreach ($ativas as $c) {
            $plano = $c['espaco']['planoCodigo'];
            $contasPorPlano[$plano] = ($contasPorPlano[$plano] ?? 0) + 1;
            $receita += ($precos[$plano] ?? 0) + self::convenioEmCentavos($c['convenio']);
            $usuarios += $c['usuarios'];
            $bytes += $c['bytes'];
        }

        $gb = $bytes / self::BYTES_POR_GB;
        $baseDoGb = array_sum(array_map(
            static fn (LancamentoDeCusto $l): int => $l->getCategoria()->contaNoCustoPorGb() ? $l->getValorCentavos() : 0,
            $lancamentos,
        ));
        $custoPorGb = $gb > 0 && $baseDoGb > 0 ? $baseDoGb / $gb : null;
        $margem = $receita - $custo;
        $nContas = count($ativas);

        return [
            'mes' => $mes,
            'kpis' => [
                'custoCentavos' => $custo,
                'lancamentos' => count($lancamentos),
                'receitaCentavos' => $receita,
                'margemCentavos' => $margem,
                'margemPercentual' => $receita > 0 ? (int) round($margem / $receita * 100) : null,
                'contas' => $nContas,
                'usuarios' => $usuarios,
                'custoPorContaCentavos' => $nContas > 0 ? (int) round($custo / $nContas) : 0,
                'custoPorUsuarioCentavos' => $usuarios > 0 ? (int) round($custo / $usuarios) : 0,
                'gbArmazenados' => round($gb, 1),
                'custoPorGbCentavos' => $custoPorGb === null ? null : (int) round($custoPorGb),
            ],
            'categorias' => self::categorias($lancamentos, $custo),
            'planos' => self::planos($planos, $contasPorPlano, $custoPorGb),
            'projecao' => array_map(static fn (int $m): array => [
                'meses' => $m,
                'custoCentavos' => $custo * $m,
                'receitaCentavos' => $receita * $m,
                'margemCentavos' => $margem * $m,
            ], self::PROJECOES_EM_MESES),
            'lancamentos' => array_map(static fn (LancamentoDeCusto $l): array => [
                'id' => $l->getId(),
                'categoria' => $l->getCategoria()->value,
                'fornecedor' => $l->getFornecedor(),
                'valorCentavos' => $l->getValorCentavos(),
                'origem' => $l->getOrigem()->value,
                'observacao' => $l->getObservacao(),
            ], $lancamentos),
        ];
    }

    /** @param array{modo: string, valor: float} $convenio */
    private static function convenioEmCentavos(array $convenio): int
    {
        $cobra = $convenio['modo'] === ConvenioDaUf::MODO_DIFERENCIADO || $convenio['modo'] === ConvenioDaUf::MODO_SEM;

        return $cobra ? (int) round($convenio['valor'] * 100) : 0;
    }

    /**
     * Todas as categorias, na ordem do enum, também as sem lançamento (a tela mostra "não lançado").
     *
     * @param list<LancamentoDeCusto> $lancamentos
     *
     * @return list<array{categoria: string, totalCentavos: int, lancamentos: int, percentual: int}>
     */
    private static function categorias(array $lancamentos, int $custo): array
    {
        $linhas = [];
        foreach (CategoriaDeCusto::cases() as $categoria) {
            $deste = array_filter($lancamentos, static fn (LancamentoDeCusto $l): bool => $l->getCategoria() === $categoria);
            $total = array_sum(array_map(static fn (LancamentoDeCusto $l): int => $l->getValorCentavos(), $deste));
            $linhas[] = [
                'categoria' => $categoria->value,
                'totalCentavos' => $total,
                'lancamentos' => count($deste),
                'percentual' => $custo > 0 ? (int) round($total / $custo * 100) : 0,
            ];
        }

        return $linhas;
    }

    /**
     * @param list<array{codigo: string, nome: string, cotaGb: int, precoCentavos: int, ativo: bool}> $planos
     * @param array<string, int>                                                                       $contasPorPlano
     *
     * @return list<array{codigo: string, nome: string, contas: int, precoCentavos: int, custoCentavos: ?int, margemCentavos: ?int}>
     */
    private static function planos(array $planos, array $contasPorPlano, ?float $custoPorGb): array
    {
        $linhas = [];
        foreach ($planos as $p) {
            if (!$p['ativo'] && !isset($contasPorPlano[$p['codigo']])) {
                continue;
            }
            $custoDoPlano = $custoPorGb !== null && $p['cotaGb'] > 0 ? (int) round($custoPorGb * $p['cotaGb']) : null;
            $linhas[] = [
                'codigo' => $p['codigo'],
                'nome' => $p['nome'],
                'contas' => $contasPorPlano[$p['codigo']] ?? 0,
                'precoCentavos' => $p['precoCentavos'],
                'custoCentavos' => $custoDoPlano,
                'margemCentavos' => $custoDoPlano === null ? null : $p['precoCentavos'] - $custoDoPlano,
            ];
        }

        return $linhas;
    }
}
