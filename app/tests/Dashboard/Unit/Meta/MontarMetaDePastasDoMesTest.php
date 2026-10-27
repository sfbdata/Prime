<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit\Meta;

use App\Agenda\Service\CalendarioDoSistema;
use App\Agenda\Service\DiasUteisNacionais;
use App\Dashboard\DTO\MetaDePastasDoMesOutput;
use App\Dashboard\Entity\DashboardMetaPastas;
use App\Dashboard\Meta\MontarMetaDePastasDoMes;
use App\Dashboard\Repository\DashboardMetaPastasRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Meta de pastas do mês na tela (DASH-016): meta vigente, realizado do card e ritmo esperado em DIAS ÚTEIS.
 * Outubro/2026 tem 21 dias úteis (o 12 é feriado); até o dia 20 passaram 13.
 */
#[CoversClass(MontarMetaDePastasDoMes::class)]
#[CoversClass(MetaDePastasDoMesOutput::class)]
final class MontarMetaDePastasDoMesTest extends TestCase
{
    private const FILTROS_DO_MES = ['data_de' => '2026-10-01', 'data_ate' => '2026-10-31', 'responsavel' => '', 'cargo' => ''];

    private DashboardMetaPastasRepository&MockObject $repo;
    private MontarMetaDePastasDoMes $sut;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->repo   = $this->createMock(DashboardMetaPastasRepository::class);
        $this->sut    = new MontarMetaDePastasDoMes($this->repo, new DiasUteisNacionais(new CalendarioDoSistema()));
        $this->tenant = new Tenant();
    }

    private function vigente(int $valor, string $mes): void
    {
        $this->repo->method('buscarVigenteEm')->willReturn(new DashboardMetaPastas($this->tenant, new \DateTimeImmutable($mes), $valor, new User()));
    }

    #[TestDox('Com meta: esperado = meta x dias úteis decorridos / total de dias úteis; abaixo do ritmo, faltam e por dia úteis')]
    public function testAbaixoDoRitmo(): void
    {
        $this->vigente(42, '2026-10-01');

        $m = $this->sut->montar($this->tenant, new \DateTimeImmutable('2026-10-20 10:00'), self::FILTROS_DO_MES, 20);

        self::assertTrue($m->visivel);
        self::assertSame(42, $m->meta);
        self::assertSame(21, $m->diasUteisTotal);
        self::assertSame(13, $m->diasUteisDecorridos);
        self::assertSame(26, $m->esperado(), '42 x 13 / 21');
        self::assertFalse($m->noRitmo(), '20 < 26');
        self::assertFalse($m->batida());
        self::assertSame(22, $m->faltam());
        self::assertSame(8, $m->diasUteisRestantes());
        self::assertEqualsWithDelta(2.75, $m->porDia(), 0.001);
        self::assertSame(14, $m->porSemana(), 'ceil(2,75 x 5)');
        self::assertEqualsWithDelta(47.62, $m->percentual(), 0.01);
        self::assertEqualsWithDelta(61.9, $m->percentualEsperado(), 0.01);
    }

    #[TestDox('No ritmo (realizado >= esperado) e meta batida (realizado >= meta, percentual travado em 100)')]
    public function testNoRitmoEBatida(): void
    {
        $this->vigente(42, '2026-10-01');

        $noRitmo = $this->sut->montar($this->tenant, new \DateTimeImmutable('2026-10-20'), self::FILTROS_DO_MES, 26);
        self::assertTrue($noRitmo->noRitmo());
        self::assertFalse($noRitmo->batida());

        $batida = $this->sut->montar($this->tenant, new \DateTimeImmutable('2026-10-20'), self::FILTROS_DO_MES, 50);
        self::assertTrue($batida->batida());
        self::assertSame(0, $batida->faltam());
        self::assertSame(100.0, $batida->percentual());
    }

    #[TestDox('Sem meta vigente, ou com a de valor 0 (removida): meta nula, e ainda visível para quem pode definir')]
    public function testSemMeta(): void
    {
        $this->repo->method('buscarVigenteEm')->willReturnOnConsecutiveCalls(
            null,
            new DashboardMetaPastas($this->tenant, new \DateTimeImmutable('2026-10-01'), 0, new User()),
        );

        foreach ([1, 2] as $_) {
            $m = $this->sut->montar($this->tenant, new \DateTimeImmutable('2026-10-20'), self::FILTROS_DO_MES, 5);
            self::assertNull($m->meta);
            self::assertNull($m->desde);
            self::assertFalse($m->temMeta());
            self::assertTrue($m->visivel);
        }
    }

    #[TestDox('Meta herdada de mês anterior: desde antes do mês corrente')]
    public function testHerdada(): void
    {
        $this->vigente(20, '2026-08-01');

        $m = $this->sut->montar($this->tenant, new \DateTimeImmutable('2026-10-20'), self::FILTROS_DO_MES, 5);

        self::assertSame('2026-08', $m->desde);
        self::assertSame('2026-10', $m->mes);
        self::assertTrue($m->herdada());
    }

    #[TestDox('Fora do mês inteiro ou com responsável ou cargo filtrado, a meta não aparece no card (mas o estado segue disponível)')]
    public function testRecortesEscondem(): void
    {
        $this->vigente(20, '2026-10-01');
        $agora = new \DateTimeImmutable('2026-10-20');

        self::assertFalse($this->sut->montar($this->tenant, $agora, ['data_de' => '2026-10-01', 'data_ate' => '2026-10-20'] + self::FILTROS_DO_MES, 5)->visivel, 'mês até hoje');
        self::assertFalse($this->sut->montar($this->tenant, $agora, ['responsavel' => '7'] + self::FILTROS_DO_MES, 5)->visivel);
        self::assertFalse($this->sut->montar($this->tenant, $agora, ['cargo' => 'Advogado'] + self::FILTROS_DO_MES, 5)->visivel);
        self::assertTrue($this->sut->montar($this->tenant, $agora, self::FILTROS_DO_MES, 5)->visivel);
    }

    #[TestDox('Primeiro dia útil ainda não passou (mês começando no fim de semana): esperado 0, sempre no ritmo')]
    public function testAntesDoPrimeiroDiaUtil(): void
    {
        $this->vigente(30, '2026-08-01');

        $m = $this->sut->montar($this->tenant, new \DateTimeImmutable('2026-08-01'), ['data_de' => '2026-08-01', 'data_ate' => '2026-08-31'], 0);

        self::assertSame(0, $m->diasUteisDecorridos);
        self::assertSame(0, $m->esperado());
        self::assertTrue($m->noRitmo());
    }

    #[TestDox('A busca é do escritório da sessão: o repositório recebe o mesmo Tenant')]
    public function testConsultaComOTenantDaSessao(): void
    {
        $this->repo->expects($this->once())->method('buscarVigenteEm')->with($this->tenant, $this->anything())->willReturn(null);

        $this->sut->montar($this->tenant, new \DateTimeImmutable('2026-10-20'), self::FILTROS_DO_MES, 0);
    }

    #[TestDox('Simular dia: só os dias úteis decorridos mudam (realizado e meta são os de verdade); dia de outro mês não simula')]
    public function testComDiaSimulado(): void
    {
        $this->vigente(42, '2026-10-01');
        $real = $this->sut->montar($this->tenant, new \DateTimeImmutable('2026-10-20'), self::FILTROS_DO_MES, 20);

        $sim = $this->sut->comDiaSimulado($real, new \DateTimeImmutable('2026-10-06'));

        self::assertSame(4, $sim->diasUteisDecorridos, '1, 2, 5 e 6 de outubro');
        self::assertSame(21, $sim->diasUteisTotal);
        self::assertSame(20, $sim->realizado);
        self::assertSame(42, $sim->meta);
        self::assertTrue($sim->visivel);
        self::assertSame($real, $this->sut->comDiaSimulado($real, new \DateTimeImmutable('2026-11-03')));
        self::assertSame(13, $real->diasUteisDecorridos, 'o original não muda');
    }
}
