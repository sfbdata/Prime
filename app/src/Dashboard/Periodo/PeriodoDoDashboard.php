<?php

declare(strict_types=1);

namespace App\Dashboard\Periodo;

/**
 * Períodos do Dashboard (spec dashboard-parte-1).
 *
 * - Período padrão: sem `data_de` e sem `data_ate` na URL, o painel abre em "Este mês" (decisão do
 *   dono, DASH-001), do dia 1º ao ÚLTIMO dia do mês, como o desenho VS 25.10.26 (MES_DE/MES_ATE,
 *   decisão do coordenador em 26/10). Data vazia ENVIADA (o usuário apagou) não é "sem data": o
 *   período fica aberto, como sempre foi.
 * - Cartão do colaborador: abre no período do painel; as janelas Mês, Trimestre, Semestre e Ano
 *   (decisão do dono) são a alternativa, em calendário civil, do primeiro dia da janela até hoje.
 *
 * Datas em 'Y-m-d', o formato estrito que os repositórios do painel leem.
 */
final class PeriodoDoDashboard
{
    public const MES       = 'mes';
    public const TRIMESTRE = 'trimestre';
    public const SEMESTRE  = 'semestre';
    public const ANO       = 'ano';
    /** O cartão abre no período do painel (o mesmo do troféu); as janelas são a alternativa. */
    public const PERIODO   = 'periodo';

    /** Ordem do seletor do cartão. */
    public const JANELAS = [self::MES, self::TRIMESTRE, self::SEMESTRE, self::ANO];

    /**
     * "Este mês" do painel: do dia 1º ao último dia do mês de hoje. As janelas do cartão
     * (daJanela) continuam terminando hoje.
     *
     * @return array{data_de: string, data_ate: string}
     */
    public static function esteMes(\DateTimeImmutable $hoje): array
    {
        return [
            'data_de'  => $hoje->format('Y-m-01'),
            'data_ate' => $hoje->format('Y-m-t'),
        ];
    }

    /**
     * Aplica o período padrão aos filtros lidos da URL. $veioData = a URL trouxe `data_de` ou
     * `data_ate` (mesmo vazios).
     *
     * @param array<string, mixed> $filtros
     *
     * @return array<string, mixed>
     */
    public static function comPadrao(array $filtros, bool $veioData, \DateTimeImmutable $hoje): array
    {
        if ($veioData) {
            return $filtros;
        }

        return array_merge($filtros, self::esteMes($hoje));
    }

    /**
     * Do primeiro dia da janela até hoje. Janela desconhecida vale como Mês.
     *
     * @return array{data_de: string, data_ate: string}
     */
    public static function daJanela(string $janela, \DateTimeImmutable $hoje): array
    {
        $ano = (int) $hoje->format('Y');
        $mes = (int) $hoje->format('n');

        $primeiroMes = match ($janela) {
            self::TRIMESTRE => intdiv($mes - 1, 3) * 3 + 1,
            self::SEMESTRE  => $mes <= 6 ? 1 : 7,
            self::ANO       => 1,
            default         => $mes,
        };

        return [
            'data_de'  => sprintf('%04d-%02d-01', $ano, $primeiroMes),
            'data_ate' => $hoje->format('Y-m-d'),
        ];
    }

    /** Janela do cartão: a escolhida, se for uma do seletor; senão, o período do painel. */
    public static function janelaDoCartao(string $janela): string
    {
        return in_array($janela, self::JANELAS, true) ? $janela : self::PERIODO;
    }

    /**
     * O período do painel que o cartão recebe pela URL, só com datas válidas ('Y-m-d' estrito,
     * a mesma regra dos repositórios); a ponta inválida ou vazia fica '' (período aberto).
     *
     * @param array<string, mixed> $filtros
     *
     * @return array{data_de: string, data_ate: string}
     */
    public static function datasDoPainel(array $filtros): array
    {
        return [
            'data_de'  => self::dataValida($filtros['data_de'] ?? ''),
            'data_ate' => self::dataValida($filtros['data_ate'] ?? ''),
        ];
    }

    /** Aceita só string (a URL pode trazer `data_de[]=`); qualquer outra coisa vira ''. */
    private static function dataValida(mixed $valor): string
    {
        $valor = is_string($valor) ? trim($valor) : '';
        $data  = $valor === '' ? false : \DateTimeImmutable::createFromFormat('!Y-m-d', $valor);

        return $data !== false && $data->format('Y-m-d') === $valor ? $valor : '';
    }
}
