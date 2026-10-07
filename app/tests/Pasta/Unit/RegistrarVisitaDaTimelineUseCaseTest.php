<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Repository\PastaTimelineVisitaRepository;
use App\Pasta\UseCase\RegistrarVisitaDaTimelineUseCase;
use App\Service\PermissionChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * Guarda de posse do {@see RegistrarVisitaDaTimelineUseCase} (PAS-043b), sem o TenantFilter no
 * caminho: os dois cenários são idênticos (permissão de ver CONCEDIDA nos dois) e só o tenant
 * passado muda. Assim a recusa só pode vir de `$pasta->getTenant() !== $tenant` — a permissão
 * não serve de segunda barreira que deixaria o teste verde com o guarda apagado.
 */
#[CoversClass(RegistrarVisitaDaTimelineUseCase::class)]
final class RegistrarVisitaDaTimelineUseCaseTest extends TestCase
{
    private PastaTimelineVisitaRepository&MockObject $repository;
    private RegistrarVisitaDaTimelineUseCase $useCase;
    private Tenant $tenant;
    private User $usuario;
    private Pasta $pasta;
    private \DateTimeImmutable $agora;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(PastaTimelineVisitaRepository::class);
        $permissao        = $this->createStub(PermissionChecker::class);
        $permissao->method('canAccessResource')->willReturn(true);
        $this->agora = new \DateTimeImmutable('2026-10-07 10:00:00');
        $relogio     = $this->createStub(ClockInterface::class);
        $relogio->method('now')->willReturn($this->agora);

        $this->useCase = new RegistrarVisitaDaTimelineUseCase($this->repository, $permissao, $relogio);

        $this->tenant  = new Tenant();
        $this->usuario = new User();
        $this->pasta   = new Pasta();
        $this->pasta->setNup('1234');
        $this->pasta->setTenant($this->tenant);
    }

    #[TestDox('Pasta do escritório da sessão: registra a abertura e devolve a visita anterior')]
    public function testPastaDoEscritorioRegistra(): void
    {
        $anterior = new \DateTimeImmutable('2026-10-01 09:00:00');
        $this->repository
            ->expects(self::once())
            ->method('registrarAbertura')
            ->with($this->pasta, $this->usuario, $this->tenant, $this->agora)
            ->willReturn($anterior);

        self::assertSame($anterior, $this->useCase->executar($this->pasta, $this->usuario, $this->tenant));
    }

    #[TestDox('Mesmo cenário, só o tenant muda: pasta de OUTRO escritório é recusada e nada é gravado')]
    public function testPastaDeOutroEscritorioERecusada(): void
    {
        $this->repository->expects(self::never())->method('registrarAbertura');

        $this->expectException(PastaDeOutroEscritorioException::class);

        $this->useCase->executar($this->pasta, $this->usuario, new Tenant());
    }
}
