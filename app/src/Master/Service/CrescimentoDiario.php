<?php

declare(strict_types=1);

namespace App\Master\Service;

use App\Master\Entity\MetricaDiariaDoSistema;
use App\Master\UseCase\ConsultarVisaoGeralUseCase;

/**
 * As séries por DIA do gráfico Crescimento do Painel do Master (desenho 05 - Perfis 1.2, VS 03.11.26): a visão
 * "Diário" e a linha "Acessos ao sistema". Só contas, sem banco: quem chama traz os números.
 *
 * Métricas: o valor ACUMULADO no fim de cada dia, do 1º de janeiro do primeiro ano com dado até hoje; o resto do
 * ano corrente fica nulo, como no gráfico mensal. O dia com foto ({@see MetricaDiariaDoSistema}) usa a foto; o
 * dia sem foto (o passado, de antes da tarefa diária existir, ou um dia em que ela não rodou) é reconstruído pela
 * data de criação. "Novos no dia" é derivado no navegador, como o "Novos no mês".
 *
 * Acessos: quantas pessoas entraram em cada dia. Não há histórico anterior ao registro: os dias antes do primeiro
 * acesso gravado ficam nulos (a linha começa ali, decisão do Samuel de 04/11/2026); dali em diante, o dia sem
 * acesso é zero.
 */
final class CrescimentoDiario
{
    /**
     * @param array{contas: array<string, float>, usuarios: array<string, float>, pastas: array<string, float>, bytes: array<string, float>} $entradas entradas por dia ('YYYY-MM-DD')
     * @param array<string, array<string, float>>                                                                                            $fotos    métrica => ['YYYY-MM-DD' => valor]
     *
     * @return array<int, array{contas: list<float|null>, usuarios: list<float|null>, pastas: list<float|null>, gb: list<float|null>}> ano => série (um valor por dia do ano)
     */
    public static function metricas(array $entradas, array $fotos, \DateTimeImmutable $hoje): array
    {
        $dias = array_merge(...array_map('array_keys', array_values($entradas)), ...array_map('array_keys', array_values($fotos)));
        $anoAtual = (int) $hoje->format('Y');
        $primeiroAno = $dias === [] ? $anoAtual : min($anoAtual, (int) substr((string) min($dias), 0, 4));
        $hojeTexto = $hoje->format('Y-m-d');

        $acumulado = [MetricaDiariaDoSistema::CONTAS => 0.0, MetricaDiariaDoSistema::USUARIOS => 0.0, MetricaDiariaDoSistema::PASTAS => 0.0, MetricaDiariaDoSistema::BYTES => 0.0];
        $saida = [];
        for ($ano = $primeiroAno; $ano <= $anoAtual; $ano++) {
            $serie = ['contas' => [], 'usuarios' => [], 'pastas' => [], 'gb' => []];
            foreach (self::diasDoAno($ano) as $dia) {
                foreach ($acumulado as $metrica => $valor) {
                    $acumulado[$metrica] = $valor + ($entradas[$metrica][$dia] ?? 0.0);
                }
                $futuro = $dia > $hojeTexto;
                foreach ([MetricaDiariaDoSistema::CONTAS, MetricaDiariaDoSistema::USUARIOS, MetricaDiariaDoSistema::PASTAS] as $metrica) {
                    $serie[$metrica][] = $futuro ? null : ($fotos[$metrica][$dia] ?? $acumulado[$metrica]);
                }
                $bytes = $fotos[MetricaDiariaDoSistema::BYTES][$dia] ?? $acumulado[MetricaDiariaDoSistema::BYTES];
                $serie['gb'][] = $futuro ? null : round($bytes / ConsultarVisaoGeralUseCase::BYTES_POR_GB, 3);
            }
            $saida[$ano] = $serie;
        }

        return $saida;
    }

    /**
     * @param array<string, int> $pessoasPorDia 'YYYY-MM-DD' => pessoas que entraram
     *
     * @return array<int, list<int|null>> ano => pessoas por dia do ano (nulo antes do primeiro registro e no futuro)
     */
    public static function acessos(array $pessoasPorDia, \DateTimeImmutable $hoje): array
    {
        if ($pessoasPorDia === []) {
            return [];
        }

        $primeiroDia = (string) min(array_keys($pessoasPorDia));
        $hojeTexto   = $hoje->format('Y-m-d');
        $saida = [];
        for ($ano = (int) substr($primeiroDia, 0, 4), $anoAtual = (int) $hoje->format('Y'); $ano <= $anoAtual; $ano++) {
            $saida[$ano] = array_map(
                static fn (string $dia): ?int => $dia < $primeiroDia || $dia > $hojeTexto ? null : ($pessoasPorDia[$dia] ?? 0),
                self::diasDoAno($ano),
            );
        }

        return $saida;
    }

    /** @return list<string> 'YYYY-MM-DD' de 1º de janeiro a 31 de dezembro */
    private static function diasDoAno(int $ano): array
    {
        $dias = [];
        $dia  = new \DateTimeImmutable(sprintf('%04d-01-01', $ano));
        while ((int) $dia->format('Y') === $ano) {
            $dias[] = $dia->format('Y-m-d');
            $dia    = $dia->modify('+1 day');
        }

        return $dias;
    }
}
