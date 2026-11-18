<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Unit;

use App\Contribua\Service\PlanoPagoDoEscritorio;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Indicacao\Service\ColegasCadastradosPorIndicacao;
use App\Inteligencia\Service\Saldo\MedidaDoUsoDeIa;
use App\Inteligencia\Service\SaldoDeIaDaPessoa;
use App\Repository\UserTenantRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(SaldoDeIaDaPessoa::class)]
final class SaldoDeIaDaPessoaTest extends TestCase
{
    private UserTenantRepository&MockObject $vinculos;
    private PlanoPagoDoEscritorio&MockObject $planos;
    private ColegasCadastradosPorIndicacao&MockObject $colegas;
    private MedidaDoUsoDeIa&MockObject $medida;
    private User $user;

    protected function setUp(): void
    {
        $this->vinculos = $this->createMock(UserTenantRepository::class);
        $this->planos = $this->createMock(PlanoPagoDoEscritorio::class);
        $this->colegas = $this->createMock(ColegasCadastradosPorIndicacao::class);
        $this->medida = $this->createMock(MedidaDoUsoDeIa::class);
        $this->user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($this->user, 7);
    }

    private function escritorio(int $id): Tenant
    {
        $tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($tenant, $id);

        return $tenant;
    }

    /** @param list<int> $escritorios os vínculos ativos da pessoa; @param list<int> $semPlano os que não pagam */
    private function cenario(array $escritorios, array $semPlano, int $colegas, string $unidade, int $franquia, int $porColega, int $usado): SaldoDeIaDaPessoa
    {
        $this->vinculos->method('findActiveByUser')->willReturn(array_map(fn (int $id): UserTenant => new UserTenant($this->user, $this->escritorio($id)), $escritorios));
        $this->planos->method('semPlanoPago')->willReturn($semPlano);
        $this->colegas->method('contar')->with(7)->willReturn($colegas);
        $this->medida->method('unidade')->willReturn($unidade);
        $this->medida->method('franquia')->willReturn($franquia);
        $this->medida->method('porColega')->willReturn($porColega);
        $this->medida->method('usado')->willReturn($usado);

        return new SaldoDeIaDaPessoa($this->vinculos, $this->planos, $this->colegas, $this->medida);
    }

    #[TestDox('tokens, sem colega: franquia de 100 mil e leitura em peças (1,4 de 3)')]
    public function testSemColegas(): void
    {
        $saldo = $this->cenario([1], [1], 0, 'tokens', 100_000, 100_000, 46_000)->de($this->user);

        self::assertTrue($saldo->seAplica);
        self::assertSame(100_000, $saldo->tokensDireito);
        self::assertSame(46_000, $saldo->tokensUsados);
        self::assertSame(1.4, $saldo->pecasUsadas);
        self::assertSame(3, $saldo->pecasDireito);
        self::assertSame(46, $saldo->percentual);
        self::assertFalse($saldo->esgotado);
        self::assertFalse($saldo->perto);
        self::assertSame('tokens', $saldo->unidade);
    }

    #[TestDox('cada colega cadastrado soma mais 100 mil e mais 3 peças ao direito')]
    public function testComColegas(): void
    {
        $saldo = $this->cenario([1], [1], 2, 'tokens', 100_000, 100_000, 120_000)->de($this->user);

        self::assertSame(300_000, $saldo->tokensDireito);
        self::assertSame(9, $saldo->pecasDireito);
        self::assertSame(2, $saldo->colegasCadastrados);
        self::assertSame(3.6, $saldo->pecasUsadas);
        self::assertSame(40, $saldo->percentual);
        self::assertFalse($saldo->esgotado);
    }

    #[TestDox('80% ou mais fica perto; usado igual ao direito esgota; percentual não passa de 100')]
    public function testPertoEEsgotado(): void
    {
        $perto = $this->cenario([1], [1], 0, 'tokens', 100_000, 100_000, 80_000)->de($this->user);
        self::assertTrue($perto->perto);
        self::assertFalse($perto->esgotado);
    }

    #[TestDox('esgotado: usado igual ou acima do direito, percentual limitado a 100')]
    public function testEsgotado(): void
    {
        $saldo = $this->cenario([1], [1], 0, 'tokens', 100_000, 100_000, 130_000)->de($this->user);

        self::assertTrue($saldo->esgotado);
        self::assertTrue($saldo->perto);
        self::assertSame(100, $saldo->percentual);
    }

    #[TestDox('só em escritório com plano pago: não se aplica, nada usado e a medida nem é consultada')]
    public function testSoEscritorioPago(): void
    {
        $this->medida->expects($this->never())->method('usado');

        $saldo = $this->cenario([1, 2], [], 0, 'tokens', 100_000, 100_000, 999)->de($this->user);

        self::assertFalse($saldo->seAplica);
        self::assertSame(0, $saldo->tokensUsados);
        self::assertFalse($saldo->esgotado);
    }

    #[TestDox('dois escritórios grátis: a medida recebe os dois; o pago fica de fora')]
    public function testDoisEscritoriosGratis(): void
    {
        $this->vinculos->method('findActiveByUser')->willReturn([
            new UserTenant($this->user, $this->escritorio(1)),
            new UserTenant($this->user, $this->escritorio(2)),
            new UserTenant($this->user, $this->escritorio(3)),
        ]);
        $this->planos->expects($this->once())->method('semPlanoPago')->with([1, 2, 3])->willReturn([1, 3]);
        $this->colegas->method('contar')->willReturn(0);
        $this->medida->method('unidade')->willReturn('tokens');
        $this->medida->method('franquia')->willReturn(100_000);
        $this->medida->method('porColega')->willReturn(100_000);
        $this->medida->expects($this->once())->method('usado')->with($this->user, [1, 3])->willReturn(60_000);

        $saldo = (new SaldoDeIaDaPessoa($this->vinculos, $this->planos, $this->colegas, $this->medida))->de($this->user);

        self::assertSame(60_000, $saldo->tokensUsados);
        self::assertTrue($saldo->seAplica);
    }

    #[TestDox('medida em peças: o usado já é peças, sem converter; o direito em peças é 3 por pessoa')]
    public function testMedidaEmPecas(): void
    {
        $saldo = $this->cenario([1], [1], 1, 'pecas', 3, 3, 2)->de($this->user);

        self::assertSame('pecas', $saldo->unidade);
        self::assertSame(2.0, $saldo->pecasUsadas);
        self::assertSame(6, $saldo->tokensDireito);
        self::assertSame(6, $saldo->pecasDireito);
        self::assertSame(33, $saldo->percentual);
        self::assertFalse($saldo->esgotado);
    }
}
