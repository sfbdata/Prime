<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tarefa\Exception\PrazoNaoEditavelException;
use App\Tarefa\UseCase\AtualizarPrazoTarefaUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(AtualizarPrazoTarefaUseCase::class)]
final class AtualizarPrazoTarefaUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private AtualizarPrazoTarefaUseCase $useCase;
    private Tenant $tenant;
    private User $criador;
    private Tarefa $tarefa;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new AtualizarPrazoTarefaUseCase($this->em);

        $this->tenant  = new Tenant();
        $this->criador = (new User())->setEmail('criador@test.com');

        $pasta = new Pasta();
        $pasta->setTenant($this->tenant);

        $this->tarefa = new Tarefa();
        $this->tarefa->setTitulo('Contestação');
        $this->tarefa->setDescricao('...');
        $this->tarefa->setPasta($pasta);
        $this->tarefa->setCriadoPor($this->criador);
    }

    #[TestDox('Criador pode editar o prazo')]
    public function testCriadorPodeEditar(): void
    {
        self::assertTrue($this->useCase->podeEditarPrazo($this->tarefa, $this->criador, $this->tenant));
    }

    #[TestDox('Não-criador não pode editar o prazo')]
    public function testNaoCriadorNaoPodeEditar(): void
    {
        $outro = (new User())->setEmail('outro@test.com');

        self::assertFalse($this->useCase->podeEditarPrazo($this->tarefa, $outro, $this->tenant));
    }

    #[TestDox('Tenant divergente não pode editar o prazo')]
    public function testTenantDivergenteNaoPodeEditar(): void
    {
        $outroTenant = new Tenant();

        self::assertFalse($this->useCase->podeEditarPrazo($this->tarefa, $this->criador, $outroTenant));
    }

    #[TestDox('Meta sem criador (criadoPor nulo) não permite editar o prazo')]
    public function testCriadorNuloNaoPodeEditar(): void
    {
        $this->tarefa->setCriadoPor(null);

        self::assertFalse($this->useCase->podeEditarPrazo($this->tarefa, $this->criador, $this->tenant));
    }

    #[TestDox('Criador define novo prazo: seta o valor e dá flush')]
    public function testCriadorDefineNovoPrazo(): void
    {
        $novoPrazo = new \DateTimeImmutable('2026-12-31');

        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($this->tarefa, $this->criador, $this->tenant, $novoPrazo);

        self::assertSame($novoPrazo, $this->tarefa->getPrazo());
    }

    #[TestDox('Criador limpa o prazo (null)')]
    public function testCriadorLimpaPrazo(): void
    {
        $this->tarefa->setPrazo(new \DateTimeImmutable('2026-01-01'));

        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($this->tarefa, $this->criador, $this->tenant, null);

        self::assertNull($this->tarefa->getPrazo());
    }

    #[TestDox('Não-criador não consegue executar: lança exceção e não dá flush')]
    public function testNaoCriadorNaoExecuta(): void
    {
        $outro = (new User())->setEmail('outro@test.com');

        $this->em->expects($this->never())->method('flush');
        $this->expectException(PrazoNaoEditavelException::class);

        $this->useCase->executar($this->tarefa, $outro, $this->tenant, new \DateTimeImmutable('2026-12-31'));
    }
}
