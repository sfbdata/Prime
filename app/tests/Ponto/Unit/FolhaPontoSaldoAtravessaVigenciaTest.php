<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Repository\LancamentoHorasPagasRepository;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\BatidasEscolhidas;
use App\Ponto\Service\CalculadoraJornada;
use App\Ponto\Service\EscolhaDasBatidasDoDia;
use App\Ponto\Service\FolhaPontoBuilder;
use App\Ponto\Service\JornadaResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Saldo diário, mensal e anual atravessando a vigência da escolha única
 * (`docs/specs/ponto-folha-uma-batida-por-tipo.md` §10): abril pela regra legada, maio pela única
 * (vigência injetada em 01/05/2026), com os mesmos dois padrões de produção em cada mês — um dia com
 * dois repousos distintos (ambíguo) e um com a saída repetida em 40 s.
 *
 * Jornada neutra de propósito (sem dia útil: meta 0), como em `FolhaPontoBuilderHorasPagasTest`: os
 * dias sem batida entre abril e hoje valem 0 em vez de uma falta que mudaria com a data do relógio.
 */
#[CoversClass(FolhaPontoBuilder::class)]
final class FolhaPontoSaldoAtravessaVigenciaTest extends TestCase
{
    /** 08:00→09:00 + 13:00→18:00: o dia ambíguo conta o PRIMEIRO repouso, nas duas regras. */
    private const MINUTOS_DIA_AMBIGUO = 360;

    /** 09:00→12:00 + 13:00→18:00:40: a saída repetida vale a última, como sempre. */
    private const MINUTOS_DIA_SAIDA_REPETIDA = 480;

    public function testSaldoDiarioEMensalSaoOsMesmosAntesEDepoisDaVigencia(): void
    {
        $builder = $this->builder();
        $jornada = $this->jornadaNeutra();

        foreach (['2026-04' => 'legada', '2026-05' => 'única'] as $competencia => $regra) {
            $inicio = new \DateTimeImmutable($competencia . '-01');
            $rows = $builder->buildRows(
                $inicio,
                $inicio->modify('last day of this month'),
                $this->batidasDaCompetencia($competencia),
                false,
                false,
                $jornada,
                [],
                [],
                null,
                new \DateTimeImmutable('2026-04-01'),
            );

            self::assertCount(2, $rows, "competência {$competencia} ({$regra})");
            [$ambiguo, $saidaRepetida] = $rows;

            self::assertSame(self::MINUTOS_DIA_AMBIGUO, $ambiguo['saldoDia'], "saldo diário do dia ambíguo ({$regra})");
            self::assertSame('09:00:00', $ambiguo['repouso'], "a célula mostra o repouso da conta ({$regra})");
            self::assertSame([BatidasEscolhidas::MARCA_REPOUSOS_DISTINTOS], $ambiguo['aConferir']);

            self::assertSame(self::MINUTOS_DIA_SAIDA_REPETIDA, $saidaRepetida['saldoDia'], "saldo diário da saída repetida ({$regra})");
            self::assertSame('18:00:40', $saidaRepetida['saida']);
            self::assertSame([], $saidaRepetida['aConferir'], 'repetir o toque não marca o dia');

            self::assertSame(
                self::MINUTOS_DIA_AMBIGUO + self::MINUTOS_DIA_SAIDA_REPETIDA,
                $builder->saldoAcumuladoFinal($rows),
                "saldo do mês ({$regra})"
            );
        }
    }

    /**
     * A troca de regra ACONTECE na folha, no dia certo. Com a lista em ordem de horário as duas regras
     * escolhem o mesmo; a única diferença é a lista fora de ordem (a legada fica com a primeira que
     * chega, como o código antigo; a única ordena). Por isso o teste usa lista fora de ordem: se a folha
     * passasse "hoje" em vez do dia, ou ignorasse a vigência, os dois dias sairiam iguais.
     */
    public function testAFolhaApuraCadaDiaPelaRegraDaSuaData(): void
    {
        $builder = $this->builder();
        $foraDeOrdem = fn (string $dia): array => [
            $this->batida('entrada', "{$dia} 08:00:00"),
            $this->batida('repouso', "{$dia} 12:30:00"),
            $this->batida('repouso', "{$dia} 12:00:00"),
            $this->batida('retorno', "{$dia} 13:30:00"),
            $this->batida('saida', "{$dia} 17:00:00"),
        ];

        $rows = $builder->buildRows(
            new \DateTimeImmutable('2026-04-30'),
            new \DateTimeImmutable('2026-05-01'),
            [...$foraDeOrdem('2026-04-30'), ...$foraDeOrdem('2026-05-01')],
            true,
            false,
            $this->jornadaNeutra(),
            [],
            [],
            null,
            new \DateTimeImmutable('2026-04-01'),
        );

        self::assertSame('12:30:00', $rows[0]['repouso'], 'véspera da vigência: a regra legada (a primeira que chegou)');
        self::assertSame(270 + 210, $rows[0]['minutosTrabalhadosDia']);
        self::assertSame('12:00:00', $rows[1]['repouso'], 'dia da vigência: a regra única (a mais cedo)');
        self::assertSame(240 + 210, $rows[1]['minutosTrabalhadosDia']);
    }

    public function testSaldoAteOMesESaldoAnualSomamAsDuasRegras(): void
    {
        $builder = $this->builder();
        $user = $this->jornadaNeutra()->getUser();
        $mes = self::MINUTOS_DIA_AMBIGUO + self::MINUTOS_DIA_SAIDA_REPETIDA;

        // "Saldo anterior" da exportação de maio = saldo até abril.
        self::assertSame($mes, $builder->calcularSaldoAteMes($user, 2026, 4, [], null, new \DateTimeImmutable('2026-04-01'), $this->tenant()));
        self::assertSame(2 * $mes, $builder->calcularSaldoAteMes($user, 2026, 5, [], null, new \DateTimeImmutable('2026-04-01'), $this->tenant()));
        self::assertSame(2 * $mes, $builder->calcularSaldoAnual($user, 2026, [], null, new \DateTimeImmutable('2026-04-01'), $this->tenant()));
    }

    /** @return list<RegistroPonto> */
    private function batidasDaCompetencia(string $competencia): array
    {
        $diaAmbiguo = $competencia === '2026-04' ? '2026-04-07' : '2026-05-12';
        $diaSaidaRepetida = $competencia === '2026-04' ? '2026-04-08' : '2026-05-13';

        return [
            $this->batida('entrada', "{$diaAmbiguo} 08:00:00"),
            $this->batida('repouso', "{$diaAmbiguo} 09:00:00"),
            $this->batida('repouso', "{$diaAmbiguo} 12:00:00"),
            $this->batida('retorno', "{$diaAmbiguo} 13:00:00"),
            $this->batida('saida', "{$diaAmbiguo} 18:00:00"),
            $this->batida('entrada', "{$diaSaidaRepetida} 09:00:00"),
            $this->batida('repouso', "{$diaSaidaRepetida} 12:00:00"),
            $this->batida('retorno', "{$diaSaidaRepetida} 13:00:00"),
            $this->batida('saida', "{$diaSaidaRepetida} 18:00:00"),
            $this->batida('saida', "{$diaSaidaRepetida} 18:00:40"),
        ];
    }

    private function builder(): FolhaPontoBuilder
    {
        $registros = $this->createStub(RegistroPontoRepository::class);
        $registros->method('findByUserAndCompetencia')->willReturnCallback(
            fn (User $u, int $ano, int $mes): array => $ano === 2026 && in_array($mes, [4, 5], true)
                ? $this->batidasDaCompetencia(sprintf('%04d-%02d', $ano, $mes))
                : []
        );
        $justificativas = $this->createStub(JustificativaPontoRepository::class);
        $justificativas->method('findByUserAndCompetenciaIndexed')->willReturn([]);
        $lancamentos = $this->createStub(LancamentoHorasPagasRepository::class);
        $lancamentos->method('somarPorPeriodo')->willReturn(0);
        $lancamentos->method('somarPorCompetencia')->willReturn(0);

        return new FolhaPontoBuilder(
            new CalculadoraJornada(new JornadaResolver(), new EscolhaDasBatidasDoDia('2026-05-01')),
            $registros,
            $justificativas,
            $lancamentos,
        );
    }

    private function jornadaNeutra(): JornadaColaborador
    {
        $user = new User();
        $user->setEmail('vigencia@teste.com')->setFullName('Vigência');

        $jornada = new JornadaColaborador();
        $jornada->setUser($user);
        $jornada->setDiasSemana([]);
        $user->setJornadaColaborador($jornada);

        return $jornada;
    }

    private function tenant(): Tenant
    {
        return $this->createStub(Tenant::class);
    }

    private function batida(string $tipo, string $dataHora): RegistroPonto
    {
        $registro = new RegistroPonto();
        $registro->setTipo($tipo);
        $registro->setDataHora(new \DateTimeImmutable($dataHora));

        return $registro;
    }
}
