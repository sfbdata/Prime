<?php

declare(strict_types=1);

namespace App\Tests\Voto\Unit;

use App\Entity\Tenant\Tenant;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Entity\UnidadeDaAssembleia;
use App\Voto\Enum\PesoDoVoto;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\UseCase\DefinirSalaVirtualDaAssembleiaUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** Trocar o link da sala virtual com a votação já publicada. */
#[CoversClass(DefinirSalaVirtualDaAssembleiaUseCase::class)]
final class DefinirSalaVirtualDaAssembleiaUseCaseTest extends TestCase
{
    private AssembleiaRepository&MockObject $assembleias;
    private Tenant $tenant;
    private Assembleia $assembleia;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->assembleia = new Assembleia($this->tenant, 'AGO 2026', PesoDoVoto::Unidade, 'Gestora');
        $pauta = new PautaDaAssembleia($this->assembleia, 1, 'Contas');
        new OpcaoDaPauta($pauta, 1, 'Sim');
        new OpcaoDaPauta($pauta, 2, 'Não');
        new UnidadeDaAssembleia($this->assembleia, '101', str_repeat('a', 32), str_repeat('b', 64));
        $this->assembleia->publicar(new \DateTimeImmutable('-1 hour'));
        $this->assembleias = $this->createMock(AssembleiaRepository::class);
        $this->assembleias->method('buscarDoEscritorio')->willReturnCallback(
            fn (int $id, Tenant $t): ?Assembleia => 5 === $id && $t === $this->tenant ? $this->assembleia : null,
        );
    }

    #[TestDox('Publicada: grava o link, troca por outro e vazio apaga')]
    public function testGravaTrocaEApaga(): void
    {
        $this->assembleias->expects(self::exactly(3))->method('salvar');
        $caso = new DefinirSalaVirtualDaAssembleiaUseCase($this->assembleias);

        $caso->executar($this->tenant, 5, 'https://meet.google.com/abc-defg-hij');
        self::assertSame('https://meet.google.com/abc-defg-hij', $this->assembleia->getLinkSalaVirtual());
        $caso->executar($this->tenant, 5, 'http://zoom.us/j/1');
        self::assertSame('http://zoom.us/j/1', $this->assembleia->getLinkSalaVirtual());
        $caso->executar($this->tenant, 5, '');
        self::assertNull($this->assembleia->getLinkSalaVirtual());
    }

    #[TestDox('Link inválido: recusa e não salva')]
    public function testInvalidoRecusa(): void
    {
        $this->assembleias->expects(self::never())->method('salvar');

        $this->expectException(OperacaoInvalidaNaAssembleiaException::class);
        (new DefinirSalaVirtualDaAssembleiaUseCase($this->assembleias))->executar($this->tenant, 5, 'javascript:alert(1)');
    }

    #[TestDox('Outro escritório ou id inexistente: AssembleiaNaoEncontradaException e não salva')]
    public function testPosse(): void
    {
        $this->assembleias->expects(self::never())->method('salvar');
        $caso = new DefinirSalaVirtualDaAssembleiaUseCase($this->assembleias);

        foreach ([[new Tenant(), 5], [$this->tenant, 6]] as [$tenant, $id]) {
            try {
                $caso->executar($tenant, $id, 'https://meet.google.com/x');
                self::fail('Devia dar "não encontrada".');
            } catch (AssembleiaNaoEncontradaException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
