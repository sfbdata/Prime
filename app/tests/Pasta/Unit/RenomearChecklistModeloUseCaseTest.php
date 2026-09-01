<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PastaChecklistModelo;
use App\Pasta\Exception\ChecklistModeloJaExisteException;
use App\Pasta\Repository\PastaChecklistModeloRepository;
use App\Pasta\UseCase\RenomearChecklistModeloUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[CoversClass(RenomearChecklistModeloUseCase::class)]
final class RenomearChecklistModeloUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private PastaChecklistModeloRepository&MockObject $modeloRepo;
    private RenomearChecklistModeloUseCase $useCase;
    private Tenant $tenant;
    private PastaChecklistModelo $modelo;

    protected function setUp(): void
    {
        $this->em         = $this->createMock(EntityManagerInterface::class);
        $this->modeloRepo = $this->createMock(PastaChecklistModeloRepository::class);
        $this->useCase    = new RenomearChecklistModeloUseCase($this->em, $this->modeloRepo);

        $this->tenant = new Tenant();
        $this->modelo = (new PastaChecklistModelo())->setTenant($this->tenant)->setNome('Teste 2');
    }

    #[TestDox('Renomear troca o nome e grava')]
    public function testRenomeia(): void
    {
        $this->modeloRepo->method('buscarPorNome')->willReturn(null);
        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($this->modelo, 'Trabalhista', $this->tenant);

        self::assertSame('TRABALHISTA', $this->modelo->getNome());
    }

    #[TestDox('Nome de OUTRO modelo do escritório é recusado')]
    public function testColisaoComOutroModeloEhRecusada(): void
    {
        $outro = $this->modeloComId(99, 'Trabalhista');
        $this->modeloRepo->method('buscarPorNome')->willReturn($outro);
        $this->em->expects($this->never())->method('flush');

        $this->expectException(ChecklistModeloJaExisteException::class);

        $this->useCase->executar($this->modelo, 'Trabalhista', $this->tenant);
    }

    #[TestDox('Rebatizar o modelo com o nome que ele já tem não é colisão')]
    public function testRenomearParaOProprioNomeEhAceito(): void
    {
        $modelo = $this->modeloComId(7, 'Trabalhista');
        $modelo->setTenant($this->tenant);
        $this->modeloRepo->method('buscarPorNome')->willReturn($modelo);
        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($modelo, 'trabalhista', $this->tenant);

        self::assertSame('TRABALHISTA', $modelo->getNome());
    }

    #[TestDox('Nome vazio é recusado')]
    public function testNomeVazioEhRecusado(): void
    {
        $this->em->expects($this->never())->method('flush');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar($this->modelo, '  ', $this->tenant);
    }

    #[TestDox('Modelo de outro escritório não pode ser renomeado')]
    public function testModeloDeOutroEscritorioEhRecusado(): void
    {
        $this->modelo->setTenant(new Tenant());
        $this->em->expects($this->never())->method('flush');

        $this->expectException(AccessDeniedException::class);

        $this->useCase->executar($this->modelo, 'Trabalhista', $this->tenant);
    }

    private function modeloComId(int $id, string $nome): PastaChecklistModelo
    {
        $modelo = (new PastaChecklistModelo())->setTenant($this->tenant)->setNome($nome);

        $ref = new \ReflectionProperty(PastaChecklistModelo::class, 'id');
        $ref->setValue($modelo, $id);

        return $modelo;
    }
}
