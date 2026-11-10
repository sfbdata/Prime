<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Ponto\DTO\IntervalosDaFolha;
use App\Ponto\DTO\LinhaDaFolhaImpressa;
use App\Ponto\Entity\JustificativaPonto;
use App\Ponto\Service\LinhasDaFolhaImpressa;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * As linhas da folha em PDF (spec ponto-desenho-0811, E16, P35 e seção 8; desenho 16 L606 a L693): as observações do
 * dia na ordem do desenho, as células vazias, o "FERIADO" do feriado sem batida e o quadro de intervalos.
 */
#[Group('ponto')]
#[CoversClass(LinhasDaFolhaImpressa::class)]
#[CoversClass(LinhaDaFolhaImpressa::class)]
#[CoversClass(IntervalosDaFolha::class)]
final class LinhasDaFolhaImpressaTest extends TestCase
{
    private LinhasDaFolhaImpressa $impressao;

    protected function setUp(): void
    {
        $this->impressao = new LinhasDaFolhaImpressa();
    }

    #[TestDox('P35: "Registro incompleto", "Horário fora de sequência: revisar" (que vence o incompleto), "Tipo: em análise" e "Tipo: recusada"')]
    public function testPendenciasDoDia(): void
    {
        $incompleto = $this->impressao->linha($this->dia('2026-10-05', ['entrada' => '08:00:00', 'registroIncompleto' => true]), []);
        $fora = $this->impressao->linha($this->dia('2026-10-06', ['entrada' => '08:00:00', 'repouso' => '12:00:00', 'retorno' => '11:00:00', 'saida' => '17:00:00', 'registroIncompleto' => true]), []);
        $emAnalise = $this->impressao->linha($this->dia('2026-10-07'), [$this->justificativa('2026-10-07', 'falta_justificada', 'pendente')]);
        $recusada = $this->impressao->linha($this->dia('2026-10-08'), [$this->justificativa('2026-10-08', 'atestado_medico', 'rejeitado')]);

        self::assertSame('Registro incompleto', $incompleto->observacao);
        self::assertSame([true, false], [$incompleto->incompleto, $incompleto->foraDeSequencia]);
        self::assertSame('Horário fora de sequência: revisar', $fora->observacao, 'fora de sequência OU incompleto, nunca os dois');
        self::assertSame([true, false], [$fora->foraDeSequencia, $fora->incompleto], 'o incompleto do motor no par invertido não conta: a linha mostra só o fora de sequência');
        self::assertSame('Falta Justificada: em análise', $emAnalise->observacao);
        self::assertSame('Atestado Médico: recusada', $recusada->observacao);
        self::assertSame(['rejeitado'], $recusada->justificativas);
    }

    #[TestDox('Ordem do desenho (L622): feriado, pendência e cada justificativa do dia, unidos por " · "; abono parcial com o horário (decisão 7)')]
    public function testOrdemDasObservacoes(): void
    {
        $parcial = $this->justificativa('2026-10-12', 'atestado_medico', 'abonado')->setAbonoParcial(true)
            ->setHoraInicioAbono(new \DateTime('1970-01-01 09:00:00'))->setHoraFimAbono(new \DateTime('1970-01-01 12:00:00'));
        $esquecimento = $this->justificativa('2026-10-12', 'esquecimento_registro', 'pendente');

        $linha = $this->impressao->linha($this->dia('2026-10-12', ['entrada' => '13:00:00', 'isFeriado' => true, 'nomeFeriado' => 'Nossa Senhora Aparecida', 'registroIncompleto' => true]), [$parcial, $esquecimento]);

        self::assertSame('Feriado: Nossa Senhora Aparecida · Registro incompleto · Atestado Médico (parcial: 09:00 às 12:00) · Esquecimento de Registro: em análise', $linha->observacao);
        self::assertSame('Nossa Senhora Aparecida', $linha->feriado);
        self::assertSame(['abonado', 'pendente'], $linha->justificativas);
    }

    #[TestDox('Decisão 20 (L622): cada batida ajustada do dia vira "<Batida> ajustada em dd/mm/aaaa", na ordem Entrada, Repouso, Retorno e Saída, antes do feriado')]
    public function testAjustesDoDiaAntesDoFeriado(): void
    {
        $linha = $this->impressao->linha($this->dia('2026-10-12', [
            'entrada' => '13:03:00', 'saida' => '18:37:00', 'isFeriado' => true, 'nomeFeriado' => 'Nossa Senhora Aparecida',
            'saidaAjuste' => ['tipo' => 'ajuste', 'por' => 'Gestora Ana', 'em' => '14/10/2026'],
            'entradaAjuste' => ['tipo' => 'ajuste', 'por' => 'Gestora Ana', 'em' => '13/10/2026'],
            'repousoAjuste' => null, 'retornoAjuste' => null,
        ]), []);

        self::assertSame('Entrada ajustada em 13/10/2026 · Saída ajustada em 14/10/2026 · Feriado: Nossa Senhora Aparecida', $linha->observacao);
        self::assertSame(['13:03', '', '', '18:37'], $linha->batidas, 'as horas efetivas, as do motor');
    }

    #[TestDox('Decisão 20: dia sem ajuste (as quatro chaves nulas, ou ausentes) não tem "ajustada em"')]
    public function testDiaSemAjuste(): void
    {
        $nulas = $this->impressao->linha($this->dia('2026-10-12', [
            'entrada' => '08:00:00', 'isFeriado' => true, 'nomeFeriado' => 'Nossa Senhora Aparecida', 'registroIncompleto' => true,
            'entradaAjuste' => null, 'repousoAjuste' => null, 'retornoAjuste' => null, 'saidaAjuste' => null,
        ]), []);
        $ausentes = $this->impressao->linha($this->dia('2026-10-13', ['entrada' => '08:00:00']), []);

        self::assertSame('Feriado: Nossa Senhora Aparecida · Registro incompleto', $nulas->observacao);
        self::assertStringNotContainsString('ajustada em', $nulas->observacao);
        self::assertSame('', $ausentes->observacao);
    }

    #[TestDox('Ficam fora: "a conferir", "registro em outro escritório" e as marcas de home office, sem internet e trabalho externo (o desenho não mostra)')]
    public function testSemOQueODesenhoNaoMostra(): void
    {
        $linha = $this->impressao->linha($this->dia('2026-10-05', [
            'entrada' => '08:00:00', 'repouso' => '12:00:00', 'retorno' => '13:00:00', 'saida' => '17:00:00',
            'aConferir' => ['repouso'], 'batidasDesconsideradas' => [['id' => 1, 'tipo' => 'repouso', 'hora' => '12:10:00']],
            'homeOffice' => true, 'semInternet' => true, 'trabalhoExterno' => true, 'registroEmOutroEscritorio' => true,
        ]), []);

        self::assertSame('', $linha->observacao);
    }

    #[TestDox('Seção 8 da spec: batida que falta e horas do dia sem conta (dia futuro) saem em célula vazia, sem travessão')]
    public function testCelulasVazias(): void
    {
        $semSaida = $this->impressao->linha($this->dia('2026-10-05', ['entrada' => '08:00:00', 'repouso' => '12:00:00', 'retorno' => '13:00:00', 'minutosTrabalhadosDia' => 240, 'saldoDia' => 0, 'saldoAcumulado' => -90, 'registroIncompleto' => true]), []);
        $futuro = $this->impressao->linha($this->dia('2026-10-30'), []);

        self::assertSame(['08:00', '12:00', '13:00', ''], $semSaida->batidas);
        self::assertSame(['4:00', '0:00', '-1:30', true], [$semSaida->trabalhadas, $semSaida->extras, $semSaida->saldo, $semSaida->saldoNegativo]);
        self::assertSame(['', '', '', ''], $futuro->batidas);
        self::assertSame(['', '', ''], [$futuro->trabalhadas, $futuro->extras, $futuro->saldo]);
        self::assertFalse($futuro->saldoNegativo);
    }

    #[TestDox('Dia completo: batidas em HH:MM, horas, extras e saldo do mês com sinal; a semana pelo dia')]
    public function testDiaCompleto(): void
    {
        $linha = $this->impressao->linha($this->dia('2026-10-05', ['entrada' => '08:01:59', 'repouso' => '12:00:00', 'retorno' => '13:00:00', 'saida' => '18:30:00', 'minutosTrabalhadosDia' => 568, 'saldoDia' => 88, 'saldoAcumulado' => 88]), []);

        self::assertSame(['2026-10-05', '05', 'Segunda'], [$linha->data, $linha->dia, $linha->semana]);
        self::assertSame(['08:01', '12:00', '13:00', '18:30'], $linha->batidas, 'os segundos não aparecem');
        self::assertSame(['9:28', '1:28', '+1:28', false], [$linha->trabalhadas, $linha->extras, $linha->saldo, $linha->saldoNegativo]);
    }

    #[TestDox('Feriado sem batida: "FERIADO" na semana (o desenho repete no lugar das batidas); feriado com batida mostra a semana')]
    public function testFeriadoSemBatida(): void
    {
        $semBatida = $this->impressao->linha($this->dia('2026-11-02', ['isFeriado' => true, 'nomeFeriado' => 'Finados', 'minutosTrabalhadosDia' => 0, 'saldoDia' => 0, 'saldoAcumulado' => 0]), []);
        $comBatida = $this->impressao->linha($this->dia('2026-11-02', ['isFeriado' => true, 'nomeFeriado' => 'Finados', 'entrada' => '09:00:00']), []);

        self::assertSame(['FERIADO', true, 'Feriado: Finados'], [$semBatida->semana, $semBatida->feriadoSemBatida, $semBatida->observacao]);
        self::assertSame(['0:00', '0:00', '+0:00'], [$semBatida->trabalhadas, $semBatida->extras, $semBatida->saldo]);
        self::assertSame(['Segunda', false], [$comBatida->semana, $comBatida->feriadoSemBatida]);
    }

    #[TestDox('Feriado de quem monta a folha (todo dia de feriado): vale também no dia que o motor não apurou, sem mudar as horas')]
    public function testFeriadoDoMesEmDiaNaoApurado(): void
    {
        $linhas = $this->impressao->imprimir([$this->dia('2026-10-28'), $this->dia('2026-10-29')], [], ['2026-10-28' => 'Dia do Servidor Público']);

        self::assertSame(['Dia do Servidor Público', 'FERIADO', true, 'Feriado: Dia do Servidor Público'], [$linhas[0]->feriado, $linhas[0]->semana, $linhas[0]->feriadoSemBatida, $linhas[0]->observacao]);
        self::assertSame(['', '', ''], [$linhas[0]->trabalhadas, $linhas[0]->extras, $linhas[0]->saldo], 'as horas do motor (dia sem conta)');
        self::assertSame([null, 'Quinta', ''], [$linhas[1]->feriado, $linhas[1]->semana, $linhas[1]->observacao]);
    }

    /** @param array<string, string> $horarios */
    #[DataProvider('sequencias')]
    #[TestDox('Fora de sequência: um horário menor que o anterior que existe (o dia com buraco pula a casa vazia)')]
    public function testForaDeSequencia(array $horarios, bool $esperado): void
    {
        self::assertSame($esperado, LinhasDaFolhaImpressa::foraDeSequencia($horarios + ['entrada' => '', 'repouso' => '', 'retorno' => '', 'saida' => '']));
    }

    /** @return iterable<string, array{array<string, string>, bool}> */
    public static function sequencias(): iterable
    {
        yield 'em ordem' => [['entrada' => '08:00:00', 'repouso' => '12:00:00', 'retorno' => '13:00:00', 'saida' => '17:00:00'], false];
        yield 'retorno antes do repouso' => [['entrada' => '08:00:00', 'repouso' => '13:00:00', 'retorno' => '12:00:00', 'saida' => '17:00:00'], true];
        yield 'saída antes da entrada, sem intervalo' => [['entrada' => '08:00:00', 'saida' => '07:59:59'], true];
        yield 'sem repouso, o retorno compara com a entrada' => [['entrada' => '08:00:00', 'retorno' => '07:00:00'], true];
        yield 'só os segundos fora' => [['entrada' => '08:00:30', 'repouso' => '08:00:10'], true];
        yield 'uma batida só' => [['saida' => '17:00:00'], false];
        yield 'sem batida' => [[], false];
        yield 'iguais não é fora' => [['entrada' => '08:00:00', 'repouso' => '08:00:00'], false];
    }

    #[TestDox('Descanso semanal: 7 dias seguidos com batida dão "Não conforme" e " · 7 dias seguidos"; 6 dias, conforme')]
    public function testDescansoSemanal(): void
    {
        $sete = $this->diasComBatida('2026-10-05', 7);
        $seis = $this->diasComBatida('2026-10-05', 6);

        $intervalosSete = $this->impressao->intervalos($sete, 60, 660);
        $intervalosSeis = $this->impressao->intervalos($seis, 60, 660);

        self::assertSame(7, $intervalosSete->maiorSequencia);
        self::assertFalse($intervalosSete->descansoSemanalConforme());
        self::assertSame('Feriados trabalhados: 0 · Finais de semana: 2 · 7 dias seguidos', $intervalosSete->textoDaContagem());
        self::assertTrue($intervalosSeis->descansoSemanalConforme());
        self::assertSame('Feriados trabalhados: 0 · Finais de semana: 1', $intervalosSeis->textoDaContagem());
    }

    #[TestDox('Intrajornada: os dias completos com intervalo menor que o mínimo, sem o dia fora de sequência nem o incompleto')]
    public function testDiasDeIntrajornada(): void
    {
        $linhas = [
            $this->dia('2026-10-05', ['entrada' => '08:00:00', 'repouso' => '12:00:00', 'retorno' => '12:30:00', 'saida' => '17:00:00']),
            $this->dia('2026-10-06', ['entrada' => '08:00:00', 'repouso' => '12:00:00', 'retorno' => '13:00:00', 'saida' => '17:00:00']),
            $this->dia('2026-10-07', ['entrada' => '08:00:00', 'repouso' => '12:00:00', 'retorno' => '12:10:00']),
            $this->dia('2026-10-08', ['entrada' => '08:00:00', 'repouso' => '12:30:00', 'retorno' => '12:00:00', 'saida' => '17:00:00']),
            $this->dia('2026-10-09', ['entrada' => '08:00:00', 'repouso' => '12:00:00', 'retorno' => '12:59:59', 'saida' => '17:00:00']),
        ];

        $intervalos = $this->impressao->intervalos($linhas, 60, 660);

        self::assertSame(['05', '09'], $intervalos->diasIntrajornada);
        self::assertFalse($intervalos->intrajornadaConforme());
        self::assertSame('Dias 05, 09', IntervalosDaFolha::textoDosDias($intervalos->diasIntrajornada));
        self::assertSame(60, $intervalos->minimoIntrajornada);
    }

    #[TestDox('Interjornada: o descanso desde a saída do dia anterior abaixo do mínimo; dia sem saída ou fora de sequência não conta')]
    public function testDiasDeInterjornada(): void
    {
        $linhas = [
            $this->dia('2026-10-05', ['entrada' => '08:00:00', 'saida' => '23:00:00']),
            $this->dia('2026-10-06', ['entrada' => '07:00:00', 'saida' => '17:00:00']),
            $this->dia('2026-10-07', ['entrada' => '04:00:00', 'saida' => '22:00:00']),
            $this->dia('2026-10-08', ['entrada' => '08:00:00']),
            $this->dia('2026-10-09', ['entrada' => '06:00:00', 'saida' => '05:00:00']),
            $this->dia('2026-10-10', ['entrada' => '06:00:00', 'saida' => '12:00:00']),
        ];

        $intervalos = $this->impressao->intervalos($linhas, 60, 660);

        self::assertSame(['06', '08'], $intervalos->diasInterjornada, '06: 8h desde as 23h; 07: 11h desde as 17h; 08: 10h desde as 22h; 10: o 09 está fora de sequência');
        self::assertFalse($intervalos->interjornadaConforme());
        self::assertTrue($this->impressao->intervalos([], 60, 660)->interjornadaConforme());
    }

    #[TestDox('Feriados trabalhados (feriado com horas) e fins de semana com batida')]
    public function testFeriadosEFinsDeSemana(): void
    {
        $linhas = [
            $this->dia('2026-10-10', ['entrada' => '09:00:00']),
            $this->dia('2026-10-11'),
            $this->dia('2026-10-12', ['isFeriado' => true, 'nomeFeriado' => 'Aparecida', 'entrada' => '09:00:00', 'saida' => '12:00:00', 'minutosTrabalhadosDia' => 180]),
            $this->dia('2026-11-02', ['isFeriado' => true, 'nomeFeriado' => 'Finados', 'minutosTrabalhadosDia' => 0]),
        ];

        $intervalos = $this->impressao->intervalos($linhas, 60, 660);

        self::assertSame([1, 1], [$intervalos->feriadosTrabalhados, $intervalos->finaisDeSemana]);
    }

    #[TestDox('Imprimir: uma linha por dia, com as justificativas de cada dia (todas, na ordem da consulta)')]
    public function testImprimirAgrupaAsJustificativasPorDia(): void
    {
        $linhas = $this->impressao->imprimir(
            [$this->dia('2026-10-05'), $this->dia('2026-10-06')],
            [$this->justificativa('2026-10-06', 'atestado_medico', 'abonado'), $this->justificativa('2026-10-06', 'esquecimento_registro', 'rejeitado')],
        );

        self::assertCount(2, $linhas);
        self::assertSame('', $linhas[0]->observacao);
        self::assertSame('Atestado Médico · Esquecimento de Registro: recusada', $linhas[1]->observacao);
    }

    /** @param int<1, max> $quantos */
    private function diasComBatida(string $inicio, int $quantos): array
    {
        $linhas = [];
        $dia = new \DateTimeImmutable($inicio);
        for ($i = 0; $i < $quantos; ++$i) {
            $linhas[] = $this->dia($dia->modify("+{$i} days")->format('Y-m-d'), ['entrada' => '08:00:00', 'saida' => '12:00:00']);
        }
        $linhas[] = $this->dia($dia->modify("+{$quantos} days")->format('Y-m-d'));

        return $linhas;
    }

    /**
     * Uma linha no formato do `FolhaPontoBuilder::buildRows`, sem conta (como o dia futuro), com o que o teste pedir.
     *
     * @param array<string, mixed> $campos
     *
     * @return array<string, mixed>
     */
    private function dia(string $data, array $campos = []): array
    {
        $semana = (int) (new \DateTimeImmutable($data))->format('N');

        return $campos + [
            'diaMes' => substr($data, 8, 2), 'diaSemana' => 'ignorado', 'chaveDia' => $data,
            'entrada' => '', 'repouso' => '', 'retorno' => '', 'saida' => '',
            'fimSemana' => $semana >= 6, 'isFeriado' => false, 'nomeFeriado' => null,
            'minutosIntervalo' => null, 'minutosTrabalhadosDia' => null, 'saldoDia' => null, 'saldoAcumulado' => null,
            'registroIncompleto' => false, 'justificativa' => null, 'aConferir' => [],
        ];
    }

    private function justificativa(string $data, string $tipo, string $situacao): JustificativaPonto
    {
        return (new JustificativaPonto())->setData(new \DateTime($data))->setTipo($tipo)->setStatus($situacao);
    }
}
