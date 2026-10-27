<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Contribua\Repository\ConfiguracaoDoEspacoRepository;
use App\Contribua\Service\SituacaoDoEspaco;
use App\Indicacao\Repository\IndicacaoRepository;
use App\Master\Repository\PainelMasterRepository;
use App\Master\Service\FaixaEtaria;
use App\Master\Service\InscricaoOab;
use App\Master\Service\MapaDeAtuacao;
use Psr\Clock\ClockInterface;

/**
 * "Visão geral" do Painel do Master (spec perfis-master §4.3): os 8 KPIs, o gráfico Crescimento,
 * o mapa "Onde estamos atuando" e a faixa etária. Só leitura, só números: nenhum nome, e-mail ou
 * CPF sai daqui.
 *
 * `armazenamento` é o KPI do desenho 1.2 (linha 688; spec perfis-1.2-planos-e-limites P9): uso e soma dos
 * limites do RetratoDoEspaco das contas fora da quarentena COM limite, com a faixa da função `faixa()` do
 * desenho; as contas sem limite vêm à parte (quantas e quanto usam). Nulo quando nenhuma conta tem limite. `registrosDeAuditoria`
 * é o selo do botão "Segurança e auditoria" desde a abertura do painel (linha 678; P10).
 *
 * A seta de tendência do mapa é o saldo de entradas menos saídas da equipe (C6; regras no {@see MapaDeAtuacao}).
 */
final class ConsultarVisaoGeralUseCase
{
    public const BYTES_POR_GB = 1073741824;

    public function __construct(
        private readonly PainelMasterRepository $repositorio,
        private readonly ClockInterface $relogio,
        private readonly SituacaoDoEspaco $situacaoDoEspaco,
        private readonly ConfiguracaoDoEspacoRepository $configuracoes,
        private readonly IndicacaoRepository $indicacoes,
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
            'mapa'        => MapaDeAtuacao::montar($pessoas, $escritorios, $hoje, $this->saidasDaTendencia($hoje), $this->indicacoes->paraOMapa()),
            'idades'      => FaixaEtaria::distribuir(array_column($pessoas, 'nascimento'), $hoje),
            'armazenamento' => $this->armazenamentoContratado($escritorios, $consumo),
            'duplicidade' => $this->repositorio->duplicidadeDeArquivos(),
            'registrosDeAuditoria' => $this->repositorio->contarTrilhaAdministrativa(
                ConsultarAuditoriaUseCase::LIMITE,
                $hoje->modify(sprintf('-%d months', ConsultarAuditoriaUseCase::MESES)),
            ),
            'geradoEm'    => $hoje->format(\DATE_ATOM),
        ];
    }

    /**
     * @param list<array{id: int}>                                              $escritorios
     * @param array<int, array{documentos: int, bytes: int, bytesRecentes: int}> $consumo
     *
     * @return array{cotaGb: int, bytesUsados: int, bytesLivres: int, percentual: int, faixa: string, contasSemLimite: int, bytesSemLimite: int}|null
     */
    private function armazenamentoContratado(array $escritorios, array $consumo): ?array
    {
        $bytesPorConta = [];
        foreach ($escritorios as $e) {
            $bytesPorConta[$e['id']] = $consumo[$e['id']]['bytes'] ?? 0;
        }

        // Em lote: uma consulta para todas as contas (`retratarVarios`), não uma por conta.
        [$cota, $uso, $semLimite, $usoSemLimite] = [0, 0, 0, 0];
        foreach ($this->situacaoDoEspaco->retratarVarios($bytesPorConta) as $retrato) {
            if ($retrato->bytesLimite === null) {
                [$semLimite, $usoSemLimite] = [$semLimite + 1, $usoSemLimite + $retrato->bytesUsados];
                continue;
            }
            [$cota, $uso] = [$cota + $retrato->bytesLimite, $uso + $retrato->bytesUsados];
        }
        if ($cota === 0) {
            return null;
        }
        $percentual = (int) round($uso / $cota * 100);

        return [
            'cotaGb'      => intdiv($cota, self::BYTES_POR_GB),
            'bytesUsados' => $uso,
            'bytesLivres' => max(0, $cota - $uso),
            'percentual'  => $percentual,
            'faixa'       => $this->faixa($percentual),
            'contasSemLimite' => $semLimite,
            'bytesSemLimite'  => $usoSemLimite,
        ];
    }

    /** A função `faixa()` do desenho (linha 593), com as faixas que o Master configura. */
    private function faixa(int $percentual): string
    {
        $c = $this->configuracoes->atual();

        return match (true) {
            $percentual >= $c->getFaixaBloqueio() => 'bloqueio',
            $percentual >= $c->getFaixaCritico() => 'critico',
            $percentual >= $c->getFaixaAlerta() => 'alerta',
            $percentual >= $c->getFaixaAtencao() => 'atencao',
            default => 'livre',
        };
    }

    /**
     * Só o agregado do mapa (para o iframe, que não confia em query string).
     *
     * @return array<string, mixed>
     */
    public function mapa(): array
    {
        $hoje = $this->relogio->now();

        return MapaDeAtuacao::montar($this->repositorio->pessoas(), $this->repositorio->escritorios(), $hoje, $this->saidasDaTendencia($hoje), $this->indicacoes->paraOMapa());
    }

    /**
     * Saídas da equipe das duas janelas da seta de tendência (60 dias até agora; spec perfis-1.2-planos-e-limites
     * §5, C6). O fim é o segundo seguinte ao de agora porque o banco guarda `saiu_em` em segundos inteiros: assim a
     * saída deste segundo entra. As bordas de 30 e 60 dias são do {@see MapaDeAtuacao}, as mesmas das entradas.
     *
     * @return list<array{saiuEm: string, eraPrimario: bool, tenantId: int, userId: ?int, oabUf: ?string, estado: ?string}>
     */
    private function saidasDaTendencia(\DateTimeImmutable $hoje): array
    {
        return $this->repositorio->saidasRecentes($hoje->modify('-60 days'), $hoje->modify('+1 second'));
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
