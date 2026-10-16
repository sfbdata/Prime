<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Expediente\Entity\Marcador;
use App\Expediente\Repository\MarcadorRepository;
use App\Expediente\UseCase\ReordenarMarcadorUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReordenarMarcadorUseCase::class)]
final class ReordenarMarcadorUseCaseTest extends TestCase
{
    private Tenant $tenant;
    private User $usuario;

    protected function setUp(): void
    {
        $this->tenant  = new Tenant();
        $this->usuario = (new User())->setEmail('u@test.com');
    }

    #[TestDox('depois do alvo: entra logo após ele e o nível é renumerado 0..n')]
    public function testDepoisDoAlvo(): void
    {
        [$a, $b, $c] = $this->marcadores(['A', 'B', 'C']);
        $useCase = $this->useCase([1 => $a, 2 => $b, 3 => $c], [$a, $b, $c]);

        $useCase->executar(1, 3, true, $this->tenant);

        self::assertSame([0, 1, 2], [$b->getOrdem(), $c->getOrdem(), $a->getOrdem()]);
    }

    #[TestDox('antes do alvo: toma o lugar dele')]
    public function testAntesDoAlvo(): void
    {
        [$a, $b, $c] = $this->marcadores(['A', 'B', 'C']);
        $useCase = $this->useCase([1 => $a, 2 => $b, 3 => $c], [$a, $b, $c]);

        $useCase->executar(3, 1, false, $this->tenant);

        self::assertSame([0, 1, 2], [$c->getOrdem(), $a->getOrdem(), $b->getOrdem()]);
    }

    #[TestDox('submarcador para junto de um marcador de outro pai é recusado')]
    public function testNiveisDiferentes(): void
    {
        [$pai, $outro] = $this->marcadores(['Pai', 'Outro']);
        $sub = new Marcador('Sub', $this->tenant, $this->usuario, $pai);
        $useCase = $this->useCase([1 => $sub, 2 => $outro], [], false);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Submarcadores só podem ser reordenados dentro do mesmo marcador');

        $useCase->executar(1, 2, false, $this->tenant);
    }

    #[TestDox('marcador de topo para dentro de um nível de submarcadores é recusado')]
    public function testTopoParaSub(): void
    {
        [$pai, $topo] = $this->marcadores(['Pai', 'Topo']);
        $sub = new Marcador('Sub', $this->tenant, $this->usuario, $pai);
        $useCase = $this->useCase([1 => $topo, 2 => $sub], [], false);

        $this->expectExceptionMessage('Arraste para cima ou para baixo de outro marcador principal');

        $useCase->executar(1, 2, true, $this->tenant);
    }

    #[TestDox('marcador que não é do escritório não é encontrado')]
    public function testNaoEncontrado(): void
    {
        [$a] = $this->marcadores(['A']);
        $useCase = $this->useCase([1 => $a], [], false);

        $this->expectExceptionMessage('Marcador não encontrado.');

        $useCase->executar(1, 99, true, $this->tenant);
    }

    /** @return Marcador[] */
    private function marcadores(array $nomes): array
    {
        return array_map(fn (string $n): Marcador => new Marcador($n, $this->tenant, $this->usuario), $nomes);
    }

    /**
     * @param array<int, Marcador> $porId
     * @param Marcador[]           $irmaos
     */
    private function useCase(array $porId, array $irmaos, bool $grava = true): ReordenarMarcadorUseCase
    {
        $repo = $this->createStub(MarcadorRepository::class);
        $repo->method('findPorTenant')->willReturnCallback(fn (int $id): ?Marcador => $porId[$id] ?? null);
        $repo->method('findIrmaos')->willReturn($irmaos);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($grava ? $this->once() : $this->never())->method('flush');

        return new ReordenarMarcadorUseCase($repo, $em);
    }
}
