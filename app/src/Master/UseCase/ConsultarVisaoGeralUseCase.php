<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\Repository\PainelMasterRepository;
use App\Master\Service\FaixaEtaria;
use App\Master\Service\InscricaoOab;
use App\Master\Service\MapaDeAtuacao;
use Psr\Clock\ClockInterface;

/**
 * "Visão geral" do Painel do Master (spec perfis-master §4.3): os 8 KPIs, o gráfico Crescimento,
 * o mapa "Onde estamos atuando" e a faixa etária. Só leitura, só números: nenhum nome, e-mail ou
 * CPF sai daqui.
 */
final class ConsultarVisaoGeralUseCase
{
    public const BYTES_POR_GB = 1073741824;

    public function __construct(
        private readonly PainelMasterRepository $repositorio,
        private readonly ClockInterface $relogio,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function executar(): array
    {
        $hoje        = $this->relogio->now();
        $escritorios = $this->repositorio->escritorios();
        $pessoas     = $this->repositorio->pessoas();
        $consumo     = $this->repositorio->consumoPorEscritorio($hoje->modify('-30 days'));
        $pastas      = $this->repositorio->pastasPorEscritorio();

        return [
            'kpis'        => $this->kpis($escritorios, $pessoas, $consumo, $pastas),
            'crescimento' => self::crescimento($this->repositorio->entradasPorMes(), $hoje),
            'mapa'        => MapaDeAtuacao::montar($pessoas, $escritorios, $hoje),
            'idades'      => FaixaEtaria::distribuir(array_column($pessoas, 'nascimento'), $hoje),
            'geradoEm'    => $hoje->format(\DATE_ATOM),
        ];
    }

    /**
     * @param list<array{ativo: bool}>                                         $escritorios
     * @param list<array{primario: bool, oabNumero: ?string, oabUf: ?string}> $pessoas
     * @param array<int, array{documentos: int, bytes: int, bytesRecentes: int}> $consumo
     * @param array<int, int>                                                    $pastas
     *
     * @return array<string, int|float>
     */
    private function kpis(array $escritorios, array $pessoas, array $consumo, array $pastas): array
    {
        $ativas    = count(array_filter($escritorios, static fn (array $e): bool => $e['ativo']));
        $primarios = count(array_filter($pessoas, static fn (array $p): bool => $p['primario']));

        $registrosOab = [];
        foreach ($pessoas as $p) {
            if ($p['oabNumero'] !== null) {
                $registrosOab[] = InscricaoOab::normalizar($p['oabNumero'], $p['oabUf']);
            }
        }

        return [
            'escritorios'     => count($escritorios),
            'ativas'          => $ativas,
            'suspensas'       => count($escritorios) - $ativas,
            'primarios'       => $primarios,
            'secundarios'     => count($pessoas) - $primarios,
            'oabRegistros'    => count($registrosOab),
            'oabUnicas'       => count(array_unique($registrosOab)),
            'pastas'          => array_sum($pastas),
            'documentos'      => array_sum(array_column($consumo, 'documentos')),
            'bytes'           => array_sum(array_column($consumo, 'bytes')),
            'gb'              => array_sum(array_column($consumo, 'bytes')) / self::BYTES_POR_GB,
        ];
    }

    /**
     * Séries ACUMULADAS por ano e mês (o formato `CRESC` do desenho): do primeiro ano com dado até
     * o ano corrente; meses ainda por vir ficam nulos. "Novos no mês" é derivado no navegador.
     *
     * @param array{contas: array<string, float>, usuarios: array<string, float>, pastas: array<string, float>, bytes: array<string, float>} $entradas
     *
     * @return array<int, array{contas: list<float|null>, usuarios: list<float|null>, pastas: list<float|null>, gb: list<float|null>}>
     */
    public static function crescimento(array $entradas, \DateTimeImmutable $hoje): array
    {
        $anoAtual = (int) $hoje->format('Y');
        $mesAtual = (int) $hoje->format('n');

        $meses = array_merge(...array_map('array_keys', array_values($entradas)));
        $primeiroAno = $meses === [] ? $anoAtual : min($anoAtual, (int) substr((string) min($meses), 0, 4));

        $saida = [];
        $acumulado = ['contas' => 0.0, 'usuarios' => 0.0, 'pastas' => 0.0, 'gb' => 0.0];
        for ($ano = $primeiroAno; $ano <= $anoAtual; $ano++) {
            $serie = ['contas' => [], 'usuarios' => [], 'pastas' => [], 'gb' => []];
            for ($mes = 1; $mes <= 12; $mes++) {
                $chave = sprintf('%04d-%02d', $ano, $mes);
                $futuro = $ano === $anoAtual && $mes > $mesAtual;
                foreach (['contas', 'usuarios', 'pastas'] as $metrica) {
                    $acumulado[$metrica] += $entradas[$metrica][$chave] ?? 0.0;
                    $serie[$metrica][] = $futuro ? null : $acumulado[$metrica];
                }
                $acumulado['gb'] += ($entradas['bytes'][$chave] ?? 0.0) / self::BYTES_POR_GB;
                $serie['gb'][] = $futuro ? null : round($acumulado['gb'], 3);
            }
            $saida[$ano] = $serie;
        }

        return $saida;
    }
}
