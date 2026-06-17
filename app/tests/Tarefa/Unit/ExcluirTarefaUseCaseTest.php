<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Tarefa\Exception\MetaNaoExcluivelException;
use App\Tarefa\UseCase\ExcluirTarefaUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExcluirTarefaUseCase::class)]
final class ExcluirTarefaUseCaseTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private ExcluirTarefaUseCase $useCase;
    private Tenant $tenant;
    private User $criador;
    private Tarefa $tarefa;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new ExcluirTarefaUseCase($this->em);

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

    #[TestDox('Criador exclui meta recém-criada: remove + flush')]
    public function testCriadorDentroDaJanelaExclui(): void
    {
        self::assertTrue($this->useCase->podeExcluir($this->tarefa, $this->criador, $this->tenant));

        $this->em->expects($this->once())->method('remove')->with($this->tarefa);
        $this->em->expects($this->once())->method('flush');

        $this->useCase->executar($this->tarefa, $this->criador, $this->tenant);
    }

    #[TestDox('Não-criador não pode excluir')]
    public function testNaoCriadorNaoExclui(): void
    {
        $outro = (new User())->setEmail('outro@test.com');

        self::assertFalse($this->useCase->podeExcluir($this->tarefa, $outro, $this->tenant));

        $this->em->expects($this->never())->method('remove');
        $this->expectException(MetaNaoExcluivelException::class);

        $this->useCase->executar($this->tarefa, $outro, $this->tenant);
    }

    #[TestDox('Tenant divergente não pode excluir')]
    public function testTenantDivergenteNaoExclui(): void
    {
        $outroTenant = new Tenant();

        self::assertFalse($this->useCase->podeExcluir($this->tarefa, $this->criador, $outroTenant));

        $this->em->expects($this->never())->method('remove');
        $this->expectException(MetaNaoExcluivelException::class);

        $this->useCase->executar($this->tarefa, $this->criador, $outroTenant);
    }

    #[TestDox('Meta sem criador (criadoPor nulo) não pode ser excluída')]
    public function testCriadorNuloNaoExclui(): void
    {
        $this->tarefa->setCriadoPor(null);

        self::assertFalse($this->useCase->podeExcluir($this->tarefa, $this->criador, $this->tenant));
    }

    #[TestDox('Fora da janela de 24h não pode excluir')]
    public function testForaDaJanelaNaoExclui(): void
    {
        $agora = $this->tarefa->getDataCriacao()->add(new \DateInterval('PT25H'));

        self::assertFalse($this->useCase->podeExcluir($this->tarefa, $this->criador, $this->tenant, $agora));
    }

    #[TestDox('No limite exato da janela (24h) ainda pode excluir')]
    public function testNoLimiteDaJanelaExclui(): void
    {
        $agora = $this->tarefa->getDataCriacao()->add(new \DateInterval('PT24H'));

        self::assertTrue($this->useCase->podeExcluir($this->tarefa, $this->criador, $this->tenant, $agora));
    }
}
