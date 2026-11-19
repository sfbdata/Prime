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
 * então a próxima pasta criada pode ter herdado o dela. A restauração sempre funciona: com o número
 * ocupado a pasta volta com o próximo número livre (maior + 1), sob a trava da numeração.
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

    #[TestDox('Número livre: trava a numeração, restaura com o mesmo número e grava')]
    public function testNumeroLivreRestaura(): void
    {
        $pasta = $this->lapide('1239');
        $this->numeracao->expects($this->once())->method('travar')->with($this->tenant);
        $this->numeracao->expects($this->never())->method('maiorNumero');
        $this->pastas->expects($this->once())->method('findDoEscritorioPorNup')->with('1239', $this->tenant)->willReturn(null);
        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar($pasta, $this->tenant);

        self::assertFalse($pasta->estaExcluida());
        self::assertSame('1239', $pasta->getNup());
        self::assertSame('1239', $resultado->numeroAntes);
        self::assertSame('1239', $resultado->numeroAgora);
        self::assertFalse($resultado->mudouDeNumero());
    }

    #[TestDox('Número ocupado por outra pasta viva: volta com maior + 1 e a outra pasta não muda')]
    public function testNumeroOcupadoVoltaComProximoLivre(): void
    {
        $pasta    = $this->lapide('1239');
        $ocupante = (new Pasta())->setNup('1239')->setNomeCliente('MARIA NOVA')->setTenant($this->tenant);
        $this->pastas->method('findDoEscritorioPorNup')->willReturn($ocupante);
        $this->numeracao->expects($this->once())->method('travar');
        $this->numeracao->expects($this->once())->method('maiorNumero')->with($this->tenant)->willReturn(1240);
        $this->em->expects($this->once())->method('flush');

        $resultado = $this->useCase->executar($pasta, $this->tenant);

        self::assertFalse($pasta->estaExcluida());
        self::assertSame('1241', $pasta->getNup());
        self::assertSame('1239', $ocupante->getNup());
        self::assertSame('1239', $resultado->numeroAntes);
        self::assertSame('1241', $resultado->numeroAgora);
        self::assertTrue($resultado->mudouDeNumero());
    }

    #[TestDox('Pasta que não está na lixeira: recusa antes de travar a numeração')]
    public function testPastaNaoExcluidaRecusa(): void
    {
        $this->numeracao->expects($this->never())->method('travar');

        $this->expectException(\InvalidArgumentException::class);

        $this->useCase->executar((new Pasta())->setNup('1239')->setTenant($this->tenant), $this->tenant);
    }
}
