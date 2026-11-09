<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\ConfiguracaoDoPonto;
use App\Ponto\Repository\ConfiguracaoDoPontoRepository;
use App\Ponto\Service\LimitesDoBancoDeHorasDoEscritorio;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Limite e prazo do banco de horas lidos das Configurações do Ponto do escritório (E17 com a E7, P73).
 */
#[CoversClass(LimitesDoBancoDeHorasDoEscritorio::class)]
#[Group('ponto')]
final class LimitesDoBancoDeHorasDoEscritorioTest extends TestCase
{
    #[TestDox('Sem configuração salva, valem os padrões do desenho: 120 horas e 6 meses')]
    public function testSemConfiguracaoValemOsPadroes(): void
    {
        $limites = $this->limites(null);

        self::assertSame(120 * 60, $limites->limiteEmMinutos(new Tenant()));
        self::assertSame(6, $limites->prazoEmMeses(new Tenant()));
    }

    #[TestDox('Com configuração salva, valem o limite e o prazo do escritório')]
    public function testComConfiguracaoValemOsDoEscritorio(): void
    {
        $limites = $this->limites($this->configuracao(40, 12));

        self::assertSame(40 * 60, $limites->limiteEmMinutos(new Tenant()));
        self::assertSame(12, $limites->prazoEmMeses(new Tenant()));
    }

    #[TestDox('As pontas da faixa do prazo (1 e 24 meses) valem')]
    public function testPontasDaFaixaDoPrazoValem(): void
    {
        self::assertSame(1, $this->limites($this->configuracao(120, 1))->prazoEmMeses(new Tenant()));
        self::assertSame(24, $this->limites($this->configuracao(120, 24))->prazoEmMeses(new Tenant()));
    }

    /** @return iterable<string, array{int}> */
    public static function prazosForaDaFaixa(): iterable
    {
        yield 'zero' => [0];
        yield 'negativo' => [-3];
        yield 'acima de 24' => [25];
    }

    #[DataProvider('prazosForaDaFaixa')]
    #[TestDox('Prazo fora da faixa de 1 a 24 meses volta ao padrão de 6 meses')]
    public function testPrazoForaDaFaixaVoltaAoPadrao(int $prazo): void
    {
        self::assertSame(6, $this->limites($this->configuracao(120, $prazo))->prazoEmMeses(new Tenant()));
    }

    #[TestDox('Limite zero ou negativo volta ao padrão de 120 horas')]
    public function testLimiteSemHorasVoltaAoPadrao(): void
    {
        self::assertSame(120 * 60, $this->limites($this->configuracao(0, 6))->limiteEmMinutos(new Tenant()));
        self::assertSame(120 * 60, $this->limites($this->configuracao(-10, 6))->limiteEmMinutos(new Tenant()));
    }

    #[TestDox('O limite e o prazo do mesmo escritório leem a configuração uma vez; depois do reset, de novo')]
    public function testLeAConfiguracaoUmaVezPorPedido(): void
    {
        $repositorio = $this->createMock(ConfiguracaoDoPontoRepository::class);
        $repositorio->expects(self::exactly(2))->method('doTenant')->willReturn($this->configuracao(40, 12));
        $limites = new LimitesDoBancoDeHorasDoEscritorio($repositorio);
        $tenant = $this->createStub(Tenant::class);
        $tenant->method('getId')->willReturn(7);

        $limites->limiteEmMinutos($tenant);
        $limites->prazoEmMeses($tenant);
        $limites->reset();
        self::assertSame(12, $limites->prazoEmMeses($tenant));
    }

    private function limites(?ConfiguracaoDoPonto $configuracao): LimitesDoBancoDeHorasDoEscritorio
    {
        $repositorio = $this->createStub(ConfiguracaoDoPontoRepository::class);
        $repositorio->method('doTenant')->willReturn($configuracao);

        return new LimitesDoBancoDeHorasDoEscritorio($repositorio);
    }

    private function configuracao(int $limiteHoras, int $prazoMeses): ConfiguracaoDoPonto
    {
        $configuracao = $this->createStub(ConfiguracaoDoPonto::class);
        $configuracao->method('getBancoLimiteHoras')->willReturn($limiteHoras);
        $configuracao->method('getBancoPrazoMeses')->willReturn($prazoMeses);

        return $configuracao;
    }
}
