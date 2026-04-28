<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Unit;

use App\Entity\Tenant\Tenant;
use App\Tenant\UseCase\GerarCodigoFuncionario;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GerarCodigoFuncionario::class)]
final class GerarCodigoFuncionarioTest extends TestCase
{
    private function makeUseCase(array $codigosExistentes): GerarCodigoFuncionario
    {
        $rows = array_map(
            static fn(string $c) => ['codigoFuncionario' => $c],
            $codigosExistentes,
        );

        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setParameter', 'getArrayResult'])
            ->getMock();
        $query->method('setParameter')->willReturnSelf();
        $query->method('getArrayResult')->willReturn($rows);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('createQuery')->willReturn($query);

        return new GerarCodigoFuncionario($em);
    }

    public function testTenantSemCodigosRetornaPrimeiroSequencial(): void
    {
        $useCase = $this->makeUseCase([]);
        $tenant = new Tenant();

        self::assertSame('0001', $useCase->executar($tenant));
    }

    public function testRetornaProximoAposMaximoNumerico(): void
    {
        $useCase = $this->makeUseCase(['0001', '0003', '0005']);
        $tenant = new Tenant();

        self::assertSame('0006', $useCase->executar($tenant));
    }

    public function testCodigosAlfanumericosIgnoradosNoCalculo(): void
    {
        $useCase = $this->makeUseCase(['0002', 'ABC1', 'Z99']);
        $tenant = new Tenant();

        // Apenas '0002' é numérico; próximo deve ser 0003
        self::assertSame('0003', $useCase->executar($tenant));
    }

    public function testTenantComApenasCodigosAlfanumericosRetornaUm(): void
    {
        $useCase = $this->makeUseCase(['AB01', 'XYZ']);
        $tenant = new Tenant();

        self::assertSame('0001', $useCase->executar($tenant));
    }
}
