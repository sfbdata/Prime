<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit\Meta;

use App\Dashboard\DTO\DefinirMetaDePastasInput;
use App\Dashboard\DTO\RemoverMetaDePastasInput;
use App\Dashboard\Entity\DashboardMetaPastas;
use App\Dashboard\Exception\MetaDePastasInvalidaException;
use App\Dashboard\Exception\MetaDePastasNaoPermitidaException;
use App\Dashboard\Repository\DashboardMetaPastasRepository;
use App\Dashboard\UseCase\DefinirMetaDePastasDoMesUseCase;
use App\Dashboard\UseCase\RemoverMetaDePastasDoMesUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Repository\UserTenantRepository;
use App\Tenant\Service\RegraDosPrimarios;
use App\Tests\Dashboard\Unit\Zeramento\PessoaDeTeste;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Meta de pastas do mês (DASH-016): definir e remover. Só o primário; grava uma linha nova em `mes` = dia 1º do
 * mês corrente (o histórico fica); remover grava valor 0; valor fora de 1 a 9999 é recusado.
 */
#[CoversClass(DefinirMetaDePastasDoMesUseCase::class)]
#[CoversClass(RemoverMetaDePastasDoMesUseCase::class)]
final class DefinirERemoverMetaDePastasUseCaseTest extends TestCase
{
    private DashboardMetaPastasRepository&MockObject $repo;
    private UserTenantRepository&MockObject $vinculos;
    private Tenant $tenant;
    private DefinirMetaDePastasDoMesUseCase $definir;
    private RemoverMetaDePastasDoMesUseCase $remover;

    protected function setUp(): void
    {
        $this->repo     = $this->createMock(DashboardMetaPastasRepository::class);
        $this->vinculos = $this->createMock(UserTenantRepository::class);
        $this->tenant   = new Tenant();
        $regra          = new RegraDosPrimarios($this->vinculos);

        $this->definir = new DefinirMetaDePastasDoMesUseCase($this->repo, $regra);
        $this->remover = new RemoverMetaDePastasDoMesUseCase($this->repo, $regra);
    }

    private function comoPrimario(User $autor, bool $primario): void
    {
        $vinculo = (new UserTenant($autor, $this->tenant))->setTenantRole((new TenantRole())->setIsSystem($primario));
        $this->vinculos->method('findAtivoPorUserETenant')->willReturn($vinculo);
    }

    #[TestDox('O primário define a meta: grava uma linha no dia 1º do mês corrente, com o autor, e devolve o valor e o mês')]
    public function testPrimarioDefine(): void
    {
        $autor = PessoaDeTeste::criar(1);
        $this->comoPrimario($autor, true);
        $gravada = null;
        $this->repo->expects($this->once())->method('salvar')->with(
            $this->callback(static function (DashboardMetaPastas $m) use (&$gravada): bool {
                $gravada = $m;

                return true;
            }),
            true,
        );

        $saida = $this->definir->executar(new DefinirMetaDePastasInput($this->tenant, $autor, 40, new \DateTimeImmutable('2026-10-23 14:05')));

        self::assertSame(40, $saida->valor);
        self::assertSame('2026-10', $saida->desde);
        self::assertSame('2026-10-01', $gravada?->getMes()->format('Y-m-d'));
        self::assertSame($autor, $gravada?->getDefinidaPor());
        self::assertSame($this->tenant, $gravada?->getTenant(), 'o escritório é o da sessão');
    }

    #[TestDox('Quem não é primário recebe a recusa e nada é gravado, ao definir e ao remover')]
    public function testNaoPrimarioRecusado(): void
    {
        $autor = PessoaDeTeste::criar(2);
        $this->comoPrimario($autor, false);
        $this->repo->expects($this->never())->method('salvar');

        try {
            $this->definir->executar(new DefinirMetaDePastasInput($this->tenant, $autor, 40, new \DateTimeImmutable('2026-10-23')));
            self::fail('deveria recusar o definir');
        } catch (MetaDePastasNaoPermitidaException) {
        }

        $this->expectException(MetaDePastasNaoPermitidaException::class);
        $this->remover->executar(new RemoverMetaDePastasInput($this->tenant, $autor, new \DateTimeImmutable('2026-10-23')));
    }

    #[TestDox('Sem vínculo ativo com o escritório (outro escritório) também é recusado')]
    public function testSemVinculoRecusado(): void
    {
        $this->vinculos->method('findAtivoPorUserETenant')->willReturn(null);
        $this->repo->expects($this->never())->method('salvar');

        $this->expectException(MetaDePastasNaoPermitidaException::class);
        $this->definir->executar(new DefinirMetaDePastasInput($this->tenant, PessoaDeTeste::criar(3), 40, new \DateTimeImmutable('2026-10-23')));
    }

    /** @return iterable<string, array{int}> */
    public static function valoresInvalidos(): iterable
    {
        yield 'zero' => [0];
        yield 'negativo' => [-5];
        yield 'acima do teto' => [10000];
    }

    #[DataProvider('valoresInvalidos')]
    #[TestDox('Valor fora de 1 a 9999 é recusado e nada é gravado')]
    public function testValorInvalido(int $valor): void
    {
        $autor = PessoaDeTeste::criar(1);
        $this->comoPrimario($autor, true);
        $this->repo->expects($this->never())->method('salvar');

        $this->expectException(MetaDePastasInvalidaException::class);
        $this->definir->executar(new DefinirMetaDePastasInput($this->tenant, $autor, $valor, new \DateTimeImmutable('2026-10-23')));
    }

    #[TestDox('Os extremos 1 e 9999 são aceitos')]
    public function testExtremos(): void
    {
        $autor = PessoaDeTeste::criar(1);
        $this->comoPrimario($autor, true);
        $this->repo->expects($this->exactly(2))->method('salvar');

        self::assertSame(1, $this->definir->executar(new DefinirMetaDePastasInput($this->tenant, $autor, 1, new \DateTimeImmutable('2026-10-23')))->valor);
        self::assertSame(9999, $this->definir->executar(new DefinirMetaDePastasInput($this->tenant, $autor, 9999, new \DateTimeImmutable('2026-10-23')))->valor);
    }

    #[TestDox('Remover grava uma linha com valor 0 no dia 1º do mês corrente, o que encerra a meta a partir dele')]
    public function testRemoverGravaZero(): void
    {
        $autor = PessoaDeTeste::criar(1);
        $this->comoPrimario($autor, true);
        $this->repo->method('buscarVigenteEm')->willReturn(new DashboardMetaPastas($this->tenant, new \DateTimeImmutable('2026-08-01'), 20, $autor));
        $gravada = null;
        $this->repo->expects($this->once())->method('salvar')->with(
            $this->callback(static function (DashboardMetaPastas $m) use (&$gravada): bool {
                $gravada = $m;

                return true;
            }),
            true,
        );

        $saida = $this->remover->executar(new RemoverMetaDePastasInput($this->tenant, $autor, new \DateTimeImmutable('2026-10-23')));

        self::assertSame(0, $saida->valor);
        self::assertSame('2026-10', $saida->desde);
        self::assertSame(0, $gravada?->getValor());
        self::assertSame('2026-10-01', $gravada?->getMes()->format('Y-m-d'));
    }

    #[TestDox('Remover sem meta vigente (ou já removida) não grava nada')]
    public function testRemoverSemMetaNaoGrava(): void
    {
        $autor = PessoaDeTeste::criar(1);
        $this->comoPrimario($autor, true);
        $this->repo->method('buscarVigenteEm')->willReturnOnConsecutiveCalls(
            null,
            new DashboardMetaPastas($this->tenant, new \DateTimeImmutable('2026-09-01'), 0, $autor),
        );
        $this->repo->expects($this->never())->method('salvar');

        $agora = new \DateTimeImmutable('2026-10-23');
        self::assertSame(0, $this->remover->executar(new RemoverMetaDePastasInput($this->tenant, $autor, $agora))->valor);
        self::assertSame(0, $this->remover->executar(new RemoverMetaDePastasInput($this->tenant, $autor, $agora))->valor);
    }
}
