<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Ponto\Entity\BlocoJornadaColaborador;
use App\Ponto\Entity\JornadaColaborador;
use App\Ponto\Entity\RegistroPonto;
use App\Ponto\Repository\JornadaColaboradorRepository;
use App\Ponto\Repository\JustificativaPontoRepository;
use App\Ponto\Repository\LancamentoHorasPagasRepository;
use App\Ponto\Repository\RegistroPontoRepository;
use App\Ponto\Service\CalculadoraJornada;
use App\Ponto\Service\FolhaPontoBuilder;
use App\Ponto\Service\HistoricoDeJornadas;
use App\Ponto\Service\JornadaResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use App\Ponto\Repository\LotacaoComVigenciaRepository;
use App\Ponto\Service\FeriadosDaPessoa;

/**
 * Spec `docs/specs/ponto-jornada-vigencia.md` §3.2: a folha cobra de cada dia a jornada que valia nele. O caso é o do
 * Samuel em abril/2026: 40h (8h de segunda a sexta) até 21/04 e, a partir de 22/04, 44h (9h de segunda a quinta e 8h
 * na sexta).
 */
#[CoversClass(FolhaPontoBuilder::class)]
#[CoversClass(HistoricoDeJornadas::class)]
final class FolhaComVersoesDaJornadaTest extends TestCase
{
    private FolhaPontoBuilder $builder;
    private User $pessoa;

    protected function setUp(): void
    {
        $this->builder = new FolhaPontoBuilder(
            new CalculadoraJornada(new JornadaResolver()),
            $this->createStub(RegistroPontoRepository::class),
            $this->createStub(JustificativaPontoRepository::class),
            $this->createStub(LancamentoHorasPagasRepository::class),
            $this->createStub(JornadaColaboradorRepository::class),
            new FeriadosDaPessoa($this->createStub(LotacaoComVigenciaRepository::class)),
        );
        $this->pessoa = (new User())->setEmail('samuel@teste.com')->setFullName('Samuel');
    }

    #[TestDox('Antes da troca cobra 8h; depois da troca cobra 9h de segunda a quinta')]
    public function testCadaDiaCobraAJornadaQueValiaNele(): void
    {
        $historico = HistoricoDeJornadas::das([$this->quarentaHoras('2026-04-01'), $this->quarentaEQuatroHoras('2026-04-22')]);

        $linhas = $this->builder->buildRows(
            new \DateTimeImmutable('2026-04-20'),
            new \DateTimeImmutable('2026-04-23 23:59:59'),
            [...$this->oitoHoras('2026-04-20'), ...$this->oitoHoras('2026-04-23')],
            false,
            false,
            $historico,
            [],
            [],
            null,
            new \DateTimeImmutable('2026-04-01'),
        );
        $porDia = array_column($linhas, null, 'chaveDia');

        self::assertSame(0, $porDia['2026-04-20']['saldoDia'], 'segunda 20/04, jornada de 40h: 8h batidas fecham o dia');
        self::assertSame(-60, $porDia['2026-04-23']['saldoDia'], 'quinta 23/04, jornada de 44h: faltou 1h');
    }

    #[TestDox('Uma jornada solta continua valendo para todos os dias, como antes das versões')]
    public function testJornadaSoltaValeParaTodosOsDias(): void
    {
        $atual = $this->quarentaEQuatroHoras('2026-04-22');

        $comJornadaSolta = $this->builder->buildRows(
            new \DateTimeImmutable('2026-04-20'),
            new \DateTimeImmutable('2026-04-20 23:59:59'),
            $this->oitoHoras('2026-04-20'),
            false,
            false,
            $atual,
            [],
            [],
            null,
            new \DateTimeImmutable('2026-04-01'),
        );

        self::assertSame(-60, $comJornadaSolta[0]['saldoDia'], 'a versão mais antiga (aqui a única) vale também para trás');
    }

    private function quarentaHoras(string $valeAPartirDe): JornadaColaborador
    {
        return $this->versao($valeAPartirDe, 2400, [[[1, 2, 3, 4, 5], '08:00', '17:00', 480]]);
    }

    private function quarentaEQuatroHoras(string $valeAPartirDe): JornadaColaborador
    {
        return $this->versao($valeAPartirDe, 2640, [[[1, 2, 3, 4], '08:00', '18:00', 540], [[5], '08:00', '17:00', 480]]);
    }

    /** @param list<array{0: list<int>, 1: string, 2: string, 3: int}> $blocos */
    private function versao(string $valeAPartirDe, int $cargaSemanal, array $blocos): JornadaColaborador
    {
        $jornada = (new JornadaColaborador())
            ->setUser($this->pessoa)
            ->setValeAPartirDe(new \DateTimeImmutable($valeAPartirDe))
            ->setCargaHorariaSemanal($cargaSemanal);
        foreach ($blocos as [$dias, $entrada, $saida, $minutos]) {
            $jornada->addBloco(
                (new BlocoJornadaColaborador())
                    ->setDiasSemana($dias)
                    ->setEntrada($entrada)
                    ->setRepouso('12:00')
                    ->setRetorno('13:00')
                    ->setSaida($saida)
                    ->setMinutosBloco($minutos)
            );
        }

        return $jornada;
    }

    /** @return list<RegistroPonto> 08:00 a 17:00 com uma hora de almoço */
    private function oitoHoras(string $data): array
    {
        $batidas = [];
        foreach ([RegistroPonto::TIPO_ENTRADA => '08:00', RegistroPonto::TIPO_REPOUSO => '12:00', RegistroPonto::TIPO_RETORNO => '13:00', RegistroPonto::TIPO_SAIDA => '17:00'] as $tipo => $hora) {
            $batidas[] = (new RegistroPonto())
                ->setTipo($tipo)
                ->setDataHora(new \DateTimeImmutable("{$data} {$hora}:00"));
        }

        return $batidas;
    }
}
