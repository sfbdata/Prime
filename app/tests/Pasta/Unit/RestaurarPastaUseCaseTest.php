<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\NumeracaoDePastaInterface;
use App\Pasta\UseCase\RestaurarPastaUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Restaurar da lixeira com o número já reusado: desde 18/11/2026 a pasta excluída não ocupa número,
 * então a próxima pasta criada pode ter herdado o dela. A restauração é recusada, sob a trava da
 * numeração, para não voltarem duas pastas com o mesmo número.
 */
#[CoversClass(RestaurarPastaUseCase::class)]
final class RestaurarPastaUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private PastaRepository&MockObject $pastas;
    private NumeracaoDePastaInterface&MockObject $numeracao;
    private RestaurarPastaUseCase $useCase;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->em->method('wrapInTransaction')->willReturnCallback(static fn (callable $f): mixed => $f());
        $this->pastas    = $this->createMock(PastaRepository::class);
        $this->numeracao = $this->createMock(NumeracaoDePastaInterface::class);
        $this->useCase   = new RestaurarPastaUseCase($this->em, $this->pastas, $this->numeracao);

        $this->tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($this->tenant, 7);
    }

    private function lapide(string $nup): Pasta
    {
        $pasta = (new Pasta())->setNup($nup)->setTenant($this->tenant);
        $pasta->marcarExcluida((new User())->setEmail('a@test.com'), new \DateTimeImmutable());

        return $pasta;
    }

    #[TestDox('Número livre: trava a numeração, restaura e grava')]
    public function testNumeroLivreRestaura(): void
    {
        $pasta = $this->lapide('1239');
        $this->numeracao->expects($this->once())->method('travar')->with($this->tenant);
        $this->pastas->expects($this->once())->method('findDoEscritorioPorNup')->with('1239', $this->tenant)->willReturn(null);
        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($pasta, $this->tenant);

        self::assertFalse($pasta->estaExcluida());
    }

    #[TestDox('Número reusado por outra pasta viva: recusa com a mensagem e a pasta fica na lixeira')]
    public function testNumeroReusadoRecusa(): void
    {
        $pasta    = $this->lapide('1239');
        $ocupante = (new Pasta())->setNup('1239')->setNomeCliente('MARIA NOVA')->setTenant($this->tenant);
        $this->pastas->method('findDoEscritorioPorNup')->willReturn($ocupante);
        $this->em->expects($this->never())->method('flush');

        try {
            $this->useCase->executar($pasta, $this->tenant);
            self::fail('Devia recusar.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame(
                'O número 1239 já é usado por outra pasta (MARIA NOVA). Mude o número dela antes de restaurar esta.',
                $e->getMessage(),
            );
        }

        self::assertTrue($pasta->estaExcluida());
    }

    #[TestDox('Ocupante sem nome de cliente: a mensagem sai sem o parêntese')]
    public function testOcupanteSemNomeDeCliente(): void
    {
        $ocupante = (new Pasta())->setNup('1239')->setTenant($this->tenant);
        $this->pastas->method('findDoEscritorioPorNup')->willReturn($ocupante);

        $this->expectExceptionMessage('O número 1239 já é usado por outra pasta. Mude o número dela antes de restaurar esta.');

        $this->useCase->executar($this->lapide('1239'), $this->tenant);
    }

    #[TestDox('Pasta que não está na lixeira: recusa antes de travar a numeração')]
    public function testPastaNaoExcluidaRecusa(): void
    {
        $this->numeracao->expects($this->never())->method('travar');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar((new Pasta())->setNup('1239')->setTenant($this->tenant), $this->tenant);
    }
}
