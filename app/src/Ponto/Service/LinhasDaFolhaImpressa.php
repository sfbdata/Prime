<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Ponto\DTO\IntervalosDaFolha;
use App\Ponto\DTO\LinhaDaFolhaImpressa;
use App\Ponto\Entity\JustificativaPonto;

/**
 * Das linhas do {@see FolhaPontoBuilder} para o que a folha em PDF desenha (spec ponto-desenho-0811, E16, P35 e seção 8;
 * desenho 16 L606 a L630 e L667 a L693). Só formata e conta o que o motor da folha já decidiu: nenhuma regra de saldo
 * mora aqui.
 *
 * As observações do dia seguem a ordem do desenho (L622), unidas por " · ": os ajustes da batida (E10, decisão 20:
 * "<Batida> ajustada em dd/mm/aaaa", na ordem Entrada, Repouso, Retorno e Saída), "Feriado: <nome>", "Horário fora de
 * sequência: revisar" OU "Registro incompleto", e cada justificativa do dia: "<Tipo>" (abonada, com o horário do abono parcial, decisão 7), "<Tipo>: em análise" e
 * "<Tipo>: recusada". Ficam fora, como o desenho: "a conferir", "registro em outro escritório" e as marcas de home
 * office, sem internet e trabalho externo.
 *
 * "Fora de sequência" é a regra da célula "Saldo dia" da tela (`ponto/_folha_saldo_dia.html.twig`): um horário menor
 * que o anterior que existe. As duas ficam iguais (teste de paridade). A marca "incompleto" da linha é a do dia que
 * mostra "Registro incompleto": o motor marca também o dia com as quatro batidas e um par invertido, que aqui mostra
 * "Horário fora de sequência: revisar" e conta só como "a revisar" (desenho L616, L618 e L626).
 *
 * O feriado é o de TODO dia de feriado do mês (desenho L612, L622 e L678), que quem monta a folha passa em
 * `$feriadosDoMes`: o motor só marca o feriado no dia que ele apura (com jornada, não futuro, depois do início da
 * contagem). As horas continuam as do motor.
 */
final class LinhasDaFolhaImpressa
{
    public const FORA_DE_SEQUENCIA = 'Horário fora de sequência: revisar';
    public const INCOMPLETO = 'Registro incompleto';
    public const SEPARADOR = ' · ';
    public const FERIADO_SEM_BATIDA = 'FERIADO';

    private const BATIDAS = ['entrada', 'repouso', 'retorno', 'saida'];
    /** Os rótulos das batidas do desenho (`TIPOS`, L400), na ordem em que os ajustes aparecem na observação. */
    private const NOMES_DAS_BATIDAS = ['entrada' => 'Entrada', 'repouso' => 'Repouso', 'retorno' => 'Retorno', 'saida' => 'Saída'];
    private const SEMANA = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo'];
    private const UM_DIA = 86400;

    /**
     * @param array<int, array<string, mixed>> $linhas         as de `FolhaPontoBuilder::buildRows`, um dia por linha
     * @param JustificativaPonto[]             $justificativas todas as do mês, na ordem da consulta (id crescente no dia)
     * @param array<string, string>            $feriadosDoMes  'AAAA-MM-DD' => o nome do feriado, em todo dia de feriado
     *
     * @return list<LinhaDaFolhaImpressa>
     */
    public function imprimir(array $linhas, array $justificativas, array $feriadosDoMes = []): array
    {
        $porDia = [];
        foreach ($justificativas as $justificativa) {
            $porDia[$justificativa->getData()?->format('Y-m-d') ?? ''][] = $justificativa;
        }

        return array_values(array_map(fn (array $linha): LinhaDaFolhaImpressa => $this->linha($linha, $porDia[(string) $linha['chaveDia']] ?? [], $feriadosDoMes[(string) $linha['chaveDia']] ?? null), $linhas));
    }

    /**
     * @param array<string, mixed>     $linha
     * @param list<JustificativaPonto> $justificativasDoDia
     * @param ?string                  $feriadoDoDia        o nome do feriado do dia; sem ele, o que o motor marcou
     */
    public function linha(array $linha, array $justificativasDoDia, ?string $feriadoDoDia = null): LinhaDaFolhaImpressa
    {
        $fora = self::foraDeSequencia($linha);
        $batidas = self::batidas($linha);
        $feriado = $feriadoDoDia ?? (empty($linha['isFeriado']) ? null : (string) ($linha['nomeFeriado'] ?? ''));
        $semBatida = $feriado !== null && !self::temBatida($linha);
        $ajustes = $this->ajustesDoDia($linha);

        return new LinhaDaFolhaImpressa(
            (string) $linha['chaveDia'],
            sprintf('%02d', (int) $linha['diaMes']),
            $semBatida ? self::FERIADO_SEM_BATIDA : self::semana((string) $linha['chaveDia']),
            $semBatida,
            $batidas,
            self::horas($linha['minutosTrabalhadosDia'] ?? null),
            self::extras($linha['saldoDia'] ?? null),
            self::saldo(self::saldoDoMes($linha)),
            (self::saldoDoMes($linha) ?? 0) < 0,
            $this->observacao($ajustes, $linha, $fora, $feriado, $justificativasDoDia),
            $fora,
            !$fora && !empty($linha['registroIncompleto']),
            $feriado,
            array_map(static fn (JustificativaPonto $justificativa): string => (string) $justificativa->getStatus(), $justificativasDoDia),
            implode(self::SEPARADOR, $ajustes),
        );
    }

    /**
     * O "Saldo mês" da linha. No dia em andamento (hoje sem saída, E17) o motor não fecha o acumulado e grava o de
     * até ontem; a folha impressa mostra esse, como a tela (desenho L157). No dia 1º não há nenhum: célula vazia.
     *
     * @param array<string, mixed> $linha
     */
    private static function saldoDoMes(array $linha): ?int
    {
        $acumulado = $linha['saldoAcumulado'] ?? $linha['saldoAcumuladoAteOntem'] ?? null;

        return $acumulado === null ? null : (int) $acumulado;
    }

    /**
     * O quadro "CONTROLE DE INTERVALOS" (L689 a L693), com as regras do desenho: o intervalo do dia completo abaixo do
     * mínimo, o descanso desde a saída do dia anterior abaixo do mínimo (o dia fora de sequência não entra), a maior
     * sequência de dias com batida, os feriados com horas e os fins de semana com batida.
     *
     * @param array<int, array<string, mixed>> $linhas
     */
    public function intervalos(array $linhas, int $minimoIntrajornada, int $minimoInterjornada): IntervalosDaFolha
    {
        return new IntervalosDaFolha(
            $minimoIntrajornada,
            $this->diasDeIntrajornada($linhas, $minimoIntrajornada),
            $minimoInterjornada,
            $this->diasDeInterjornada($linhas, $minimoInterjornada),
            $this->maiorSequencia($linhas),
            count(array_filter($linhas, static fn (array $linha): bool => !empty($linha['isFeriado']) && ($linha['minutosTrabalhadosDia'] ?? 0) > 0)),
            count(array_filter($linhas, static fn (array $linha): bool => !empty($linha['fimSemana']) && self::temBatida($linha))),
        );
    }

    /**
     * Um horário menor que o anterior que existe (a regra da célula "Saldo dia" da tela e do `ruim` do desenho, L621).
     *
     * @param array<string, mixed> $linha
     */
    public static function foraDeSequencia(array $linha): bool
    {
        $anterior = null;
        foreach (self::BATIDAS as $campo) {
            $hora = (string) ($linha[$campo] ?? '');
            if ($hora === '') {
                continue;
            }
            if ($anterior !== null && strcmp($hora, $anterior) < 0) {
                return true;
            }
            $anterior = $hora;
        }

        return false;
    }

    /**
     * @param list<string>             $ajustes             os do dia ({@see ajustesDoDia()}), que vêm primeiro
     * @param array<string, mixed>     $linha
     * @param list<JustificativaPonto> $justificativasDoDia
     */
    private function observacao(array $ajustes, array $linha, bool $fora, ?string $feriado, array $justificativasDoDia): string
    {
        $partes = $ajustes;
        if ($feriado !== null) {
            $partes[] = 'Feriado: ' . $feriado;
        }
        if ($fora) {
            $partes[] = self::FORA_DE_SEQUENCIA;
        } elseif (!empty($linha['registroIncompleto'])) {
            $partes[] = self::INCOMPLETO;
        }
        foreach ($justificativasDoDia as $justificativa) {
            $partes[] = self::textoDaJustificativa($justificativa);
        }

        return implode(self::SEPARADOR, $partes);
    }

    /**
     * Os ajustes do dia (E10; decisão 20 da E16): "<Batida> ajustada em dd/mm/aaaa" de cada batida efetiva ajustada,
     * a primeira das observações (L622), na ordem Entrada, Repouso, Retorno e Saída. A data é a do ajuste, a mesma da
     * marca "aj" da tela; lida das chaves `{tipo}Ajuste` da linha (`FolhaPontoBuilder::ajusteDe`), nula na batida como
     * foi batida.
     *
     * @param array<string, mixed> $linha
     *
     * @return list<string>
     */
    private function ajustesDoDia(array $linha): array
    {
        $ajustes = [];
        foreach (self::NOMES_DAS_BATIDAS as $campo => $nome) {
            $ajuste = $linha[$campo . 'Ajuste'] ?? null;
            if (is_array($ajuste) && isset($ajuste['em'])) {
                $ajustes[] = $nome . ' ajustada em ' . $ajuste['em'];
            }
        }

        return $ajustes;
    }

    private static function textoDaJustificativa(JustificativaPonto $justificativa): string
    {
        $tipo = $justificativa->getLabelTipo() ?? 'Justificativa';

        return match ($justificativa->getStatus()) {
            'abonado'  => $tipo . self::abonoParcial($justificativa),
            'pendente' => $tipo . ': em análise',
            default    => $tipo . ': recusada',
        };
    }

    /** " (parcial: 09:00 às 12:00)" (decisão 7: o horário do abono parcial é função do sistema e fica). */
    private static function abonoParcial(JustificativaPonto $justificativa): string
    {
        $inicio = $justificativa->getHoraInicioAbono();
        $fim = $justificativa->getHoraFimAbono();
        if (!$justificativa->isAbonoParcial() || $inicio === null || $fim === null) {
            return '';
        }

        return sprintf(' (parcial: %s às %s)', $inicio->format('H:i'), $fim->format('H:i'));
    }

    /**
     * @param array<int, array<string, mixed>> $linhas
     *
     * @return list<string>
     */
    private function diasDeIntrajornada(array $linhas, int $minimo): array
    {
        $dias = [];
        foreach ($linhas as $linha) {
            $completo = !in_array('', self::batidas($linha), true);
            if ($completo && !self::foraDeSequencia($linha) && self::segundos($linha['retorno']) - self::segundos($linha['repouso']) < $minimo * 60) {
                $dias[] = sprintf('%02d', (int) $linha['diaMes']);
            }
        }

        return $dias;
    }

    /**
     * O descanso entre a saída de um dia e a entrada do dia seguinte (L624): só entre dias vizinhos, e nenhum dos dois
     * fora de sequência.
     *
     * @param array<int, array<string, mixed>> $linhas
     *
     * @return list<string>
     */
    private function diasDeInterjornada(array $linhas, int $minimo): array
    {
        $dias = [];
        $saidaAnterior = null;
        foreach ($linhas as $linha) {
            $fora = self::foraDeSequencia($linha);
            $entrada = (string) ($linha['entrada'] ?? '');
            if (!$fora && $saidaAnterior !== null && $entrada !== '' && self::UM_DIA - $saidaAnterior + self::segundos($entrada) < $minimo * 60) {
                $dias[] = sprintf('%02d', (int) $linha['diaMes']);
            }
            $saida = (string) ($linha['saida'] ?? '');
            $saidaAnterior = !$fora && $saida !== '' ? self::segundos($saida) : null;
        }

        return $dias;
    }

    /** @param array<int, array<string, mixed>> $linhas */
    private function maiorSequencia(array $linhas): int
    {
        $maior = 0;
        $sequencia = 0;
        foreach ($linhas as $linha) {
            $sequencia = self::temBatida($linha) ? $sequencia + 1 : 0;
            $maior = max($maior, $sequencia);
        }

        return $maior;
    }

    /**
     * @param array<string, mixed> $linha
     *
     * @return list<string>
     */
    private static function batidas(array $linha): array
    {
        return array_map(static fn (string $campo): string => substr((string) ($linha[$campo] ?? ''), 0, 5), self::BATIDAS);
    }

    /** @param array<string, mixed> $linha */
    private static function temBatida(array $linha): bool
    {
        return implode('', self::batidas($linha)) !== '';
    }

    private static function semana(string $data): string
    {
        return self::SEMANA[(int) (new \DateTimeImmutable($data))->format('N')];
    }

    private static function segundos(mixed $hora): int
    {
        $partes = array_map('intval', explode(':', (string) $hora)) + [0, 0, 0];

        return $partes[0] * 3600 + $partes[1] * 60 + $partes[2];
    }

    /** Horas do dia; vazio quando o dia não tem conta (o dia futuro, seção 8 da spec). */
    private static function horas(mixed $minutos): string
    {
        return $minutos === null ? '' : self::hhmm((int) $minutos);
    }

    private static function extras(mixed $saldoDoDia): string
    {
        return $saldoDoDia === null ? '' : self::hhmm(max(0, (int) $saldoDoDia));
    }

    private static function saldo(mixed $acumulado): string
    {
        return $acumulado === null ? '' : ((int) $acumulado < 0 ? '-' : '+') . self::hhmm((int) $acumulado);
    }

    /** "7:20", o `fHm` do desenho (L603) e o formato do PDF de hoje. */
    private static function hhmm(int $minutos): string
    {
        $absoluto = abs($minutos);

        return sprintf('%d:%02d', intdiv($absoluto, 60), $absoluto % 60);
    }
}
