<?php

declare(strict_types=1);

namespace App\Dashboard\Periodo;

/**
 * Períodos do Dashboard (spec dashboard-parte-1).
 *
 * - Período padrão: sem `data_de` e sem `data_ate` na URL, o painel abre em "Este mês", de 1º do
 *   mês até hoje (decisão do dono, DASH-001). Data vazia ENVIADA (o usuário apagou) não é "sem data":
 *   o período fica aberto, como sempre foi.
 * - Janelas do cartão do colaborador (Mês, Trimestre, Semestre, Ano): calendário civil, do primeiro
 *   dia da janela até hoje (padrão provisório).
 *
 * Datas em 'Y-m-d', o formato estrito que os repositórios do painel leem.
 */
final class PeriodoDoDashboard
{
    public const MES       = 'mes';
    public const TRIMESTRE = 'trimestre';
    public const SEMESTRE  = 'semestre';
    public const ANO       = 'ano';

    /** Ordem do seletor do cartão. */
    public const JANELAS = [self::MES, self::TRIMESTRE, self::SEMESTRE, self::ANO];

    /** @return array{data_de: string, data_ate: string} */
    public static function esteMes(\DateTimeImmutable $hoje): array
    {
        return self::daJanela(self::MES, $hoje);
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

    public static function janelaValida(string $janela): string
    {
        return in_array($janela, self::JANELAS, true) ? $janela : self::MES;
    }

    /**
     * Começo (00:00) e fim (23:59:59) do período dos filtros; null na ponta vazia ou inválida (a
     * mesma regra estrita dos repositórios de Tarefa e Pasta).
     *
     * @param array<string, mixed> $filtros
     *
     * @return array{0: \DateTimeImmutable|null, 1: \DateTimeImmutable|null}
     */
    public static function intervalo(array $filtros): array
    {
        $de  = self::lerData((string) ($filtros['data_de'] ?? ''));
        $ate = self::lerData((string) ($filtros['data_ate'] ?? ''));

        return [$de, $ate?->setTime(23, 59, 59)];
    }

    private static function lerData(string $valor): ?\DateTimeImmutable
    {
        $valor = trim($valor);
        $data  = $valor === '' ? false : \DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
        if ($data === false || $data->format('Y-m-d') !== $valor) {
            return null;
        }

        return $data;
    }
}
