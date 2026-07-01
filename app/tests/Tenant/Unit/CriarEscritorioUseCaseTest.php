<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\TenantRepository;
use App\Service\TenantBootstrapService;
use App\Tenant\DTO\CriarEscritorioInput;
use App\Tenant\UseCase\CriarEscritorioUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(CriarEscritorioUseCase::class)]
final class CriarEscritorioUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private TenantRepository&MockObject $tenantRepository;
    private TenantBootstrapService&MockObject $bootstrap;
    private CriarEscritorioUseCase $useCase;

    protected function setUp(): void
    {
        $this->em               = $this->createMock(EntityManagerInterface::class);
        $this->tenantRepository = $this->createMock(TenantRepository::class);
        $this->bootstrap        = $this->createMock(TenantBootstrapService::class);
        $this->useCase          = new CriarEscritorioUseCase($this->em, $this->tenantRepository, $this->bootstrap, 3);
    }

    private function input(string $nome, ?string $oabNumero = null, ?string $oabUf = null): CriarEscritorioInput
    {
        $input            = new CriarEscritorioInput();
        $input->nome      = $nome;
        $input->oabNumero = $oabNumero;
        $input->oabUf     = $oabUf;

        return $input;
    }

    #[TestDox('Cria o escritório quando o usuário já tem OAB na conta')]
    public function testCriaComSucessoQuandoUsuarioTemOab(): void
    {
        $criador = new User();
        $criador->setOabNumero('11111');
        $criador->setOabUf('SP');

        $this->tenantRepository->method('contarPorCriador')->willReturn(0);
        $this->bootstrap->expects($this->once())->method('bootstrap');
        $this->em->expects($this->once())->method('persist')->with(self::isInstanceOf(Tenant::class));

        $tenant = $this->useCase->executar($this->input('Banca Silva'), $criador);

        self::assertInstanceOf(Tenant::class, $tenant);
        self::assertSame('Banca Silva', $tenant->getName());
    }

    #[TestDox('Grava a OAB na conta quando o usuário ainda não tinha (colaborador virando dono)')]
    public function testCriaEGravaOabQuandoUsuarioNaoTinha(): void
    {
        $criador = new User();

        $this->tenantRepository->method('contarPorCriador')->willReturn(0);
        $this->bootstrap->expects($this->once())->method('bootstrap');

        $this->useCase->executar($this->input('Nova Banca', '12345', 'RJ'), $criador);

        self::assertSame('12345', $criador->getOabNumero());
        self::assertSame('RJ', $criador->getOabUf());
    }

    #[TestDox('Falha quando não há OAB (nem na conta nem no formulário)')]
    public function testFalhaQuandoOabAusente(): void
    {
        $criador = new User();

        $this->tenantRepository->expects($this->never())->method('contarPorCriador');
        $this->bootstrap->expects($this->never())->method('bootstrap');

        $this->expectException(\InvalidArgumentException::class);
        $this->useCase->executar($this->input('Sem OAB'), $criador);
    }

    #[TestDox('Falha quando o número da OAB não é só dígitos')]
    public function testFalhaQuandoNumeroOabInvalido(): void
    {
        $criador = new User();

        $this->bootstrap->expects($this->never())->method('bootstrap');

        $this->expectException(\InvalidArgumentException::class);
        $this->useCase->executar($this->input('X', '12A3', 'SP'), $criador);
    }

    #[TestDox('Falha quando a UF da OAB não tem 2 letras maiúsculas')]
    public function testFalhaQuandoUfOabInvalida(): void
    {
        $criador = new User();

        $this->bootstrap->expects($this->never())->method('bootstrap');

        $this->expectException(\InvalidArgumentException::class);
        $this->useCase->executar($this->input('X', '12345', 'sp'), $criador);
    }

    #[TestDox('Falha quando o limite de escritórios próprios foi atingido (RN08)')]
    public function testFalhaQuandoLimiteAtingido(): void
    {
        $criador = new User();
        $criador->setOabNumero('99999');
        $criador->setOabUf('MG');

        $this->tenantRepository->method('contarPorCriador')->willReturn(3);
        $this->bootstrap->expects($this->never())->method('bootstrap');

        $this->expectException(\DomainException::class);
        $this->useCase->executar($this->input('Quarta Banca'), $criador);
    }
}
