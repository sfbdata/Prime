<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Expediente\DTO\CriarMarcadorDTO;
use App\Expediente\Entity\Marcador;
use App\Expediente\Repository\MarcadorRepository;
use App\Expediente\UseCase\CriarMarcadorUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[CoversClass(CriarMarcadorUseCase::class)]
final class CriarMarcadorUseCaseTest extends TestCase
{
    private MarcadorRepository&MockObject $repository;
    private EntityManagerInterface&MockObject $em;
    private CriarMarcadorUseCase $useCase;
    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(MarcadorRepository::class);
        $this->em         = $this->createMock(EntityManagerInterface::class);
        $this->useCase    = new CriarMarcadorUseCase($this->repository, $this->em);

        $this->tenant  = new Tenant();
        $this->usuario = (new User())->setEmail('user@test.com');
    }

    public function testCriarMarcadorSimples(): void
    {
        $dto = new CriarMarcadorDTO(nome: 'Urgente');

        $this->repository->expects($this->never())->method('findPorTenant');
        $this->em->expects($this->once())->method('persist')->with($this->isInstanceOf(Marcador::class));
        $this->em->expects($this->once())->method('flush');

        $marcador = $this->useCase->executar($dto, $this->usuario, $this->tenant);

        $this->assertSame('Urgente', $marcador->getNome());
        $this->assertSame($this->tenant, $marcador->getTenant());
        $this->assertSame($this->usuario, $marcador->getCriadoPor());
        $this->assertNull($marcador->getPai());
        $this->assertNull($marcador->getCor());
    }

    public function testCriarMarcadorComCor(): void
    {
        $dto = new CriarMarcadorDTO(nome: 'Pendente', cor: '#fde8e8');

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $marcador = $this->useCase->executar($dto, $this->usuario, $this->tenant);

        $this->assertSame('#fde8e8', $marcador->getCor());
    }

    public function testCriarVariosMarcadoresIndependentes(): void
    {
        $nomes = ['Urgente', 'Aguardando', 'Concluído', 'Arquivado'];

        $this->em->expects($this->exactly(count($nomes)))->method('persist');
        $this->em->expects($this->exactly(count($nomes)))->method('flush');

        $marcadores = [];
        foreach ($nomes as $nome) {
            $marcadores[] = $this->useCase->executar(new CriarMarcadorDTO(nome: $nome), $this->usuario, $this->tenant);
        }

        $this->assertCount(4, $marcadores);
        foreach ($nomes as $i => $nome) {
            $this->assertSame($nome, $marcadores[$i]->getNome());
        }
    }

    public function testCriarMarcadorFilhoDeOutro(): void
    {
        $pai = new Marcador('Pai', $this->tenant, $this->usuario);
        $dto = new CriarMarcadorDTO(nome: 'Filho', paiId: 99);

        $this->repository
            ->expects($this->once())
            ->method('findPorTenant')
            ->with(99, $this->tenant)
            ->willReturn($pai);

        $this->em->expects($this->once())->method('persist');
        $this->em->expects($this->once())->method('flush');

        $marcador = $this->useCase->executar($dto, $this->usuario, $this->tenant);

        $this->assertSame('Filho', $marcador->getNome());
        $this->assertSame($pai, $marcador->getPai());
        $this->assertFalse($marcador->isRaiz());
    }

    public function testCriarMarcadorComPaiInexistenteLancaExcecao(): void
    {
        $dto = new CriarMarcadorDTO(nome: 'Órfão', paiId: 999);

        $this->repository
            ->expects($this->once())
            ->method('findPorTenant')
            ->with(999, $this->tenant)
            ->willReturn(null);

        $this->em->expects($this->never())->method('persist');

        $this->expectException(NotFoundHttpException::class);

        $this->useCase->executar($dto, $this->usuario, $this->tenant);
    }
}
