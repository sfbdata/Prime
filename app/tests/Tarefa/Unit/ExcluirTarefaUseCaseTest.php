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
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\TenantRole;
use App\Repository\AuditLogRepository;
use App\Tenant\Enum\PoderSemPrazo;
use App\Tests\Tenant\Support\CriaPoderSemPrazo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExcluirTarefaUseCase::class)]
final class ExcluirTarefaUseCaseTest extends TestCase
{
    use CriaPoderSemPrazo;

    private EntityManagerInterface&MockObject $em;
    private ExcluirTarefaUseCase $useCase;
    private Tenant $tenant;
    private User $criador;
    private Tarefa $tarefa;

    protected function setUp(): void
    {
        $this->em      = $this->createMock(EntityManagerInterface::class);
        $this->useCase = new ExcluirTarefaUseCase($this->em, $poder = $this->poderSemPrazo(), $this->auditoriaSemPrazo($poder));

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

    #[TestDox('Edição sem prazo: o Master exclui a meta de outra pessoa depois das 24h, e o uso do poder vai para a trilha')]
    public function testMasterExcluiSemPrazo(): void
    {
        (new \ReflectionProperty(Tarefa::class, 'dataCriacao'))->setValue($this->tarefa, new \DateTimeImmutable('-3 days'));
        $master = (new User())->setEmail('master@test.com')->setRoles(['ROLE_SUPER_ADMIN']);
        $trilha = $this->createMock(AuditLogRepository::class);
        $trilha->expects(self::once())->method('registrar')->with(self::callback(
            static fn (AuditLog $log): bool => $log->getAction() === 'sp_excluir' && ($log->getChanges()['papel'] ?? null) === 'Master',
        ));
        $poder = $this->poderSemPrazo();
        $useCase = new ExcluirTarefaUseCase($this->em, $poder, $this->auditoriaSemPrazo($poder, $trilha));

        self::assertSame(PoderSemPrazo::Master, $useCase->seloDaExclusao($this->tarefa, $master, $this->tenant));
        $this->em->expects(self::once())->method('remove')->with($this->tarefa);
        $useCase->executar($this->tarefa, $master, $this->tenant);
    }

    #[TestDox('Edição sem prazo: o primário exclui com a opção ligada; desligada, não; o criador fora das 24h continua sem poder')]
    public function testPrimarioComAOpcao(): void
    {
        (new \ReflectionProperty(Tarefa::class, 'dataCriacao'))->setValue($this->tarefa, new \DateTimeImmutable('-3 days'));
        $primario = (new User())->setEmail('primario@test.com')->setIsActive(true);
        $vinculo = (new UserTenant($primario, $this->tenant))->setTenantRole((new TenantRole())->setIsSystem(true));

        $ligada = new ExcluirTarefaUseCase($this->em, $p = $this->poderSemPrazo($vinculo), $this->auditoriaSemPrazo($p));
        $desligada = new ExcluirTarefaUseCase($this->em, $p = $this->poderSemPrazo($vinculo, false), $this->auditoriaSemPrazo($p));

        self::assertTrue($ligada->podeExcluir($this->tarefa, $primario, $this->tenant));
        self::assertSame(PoderSemPrazo::Primario, $ligada->seloDaExclusao($this->tarefa, $primario, $this->tenant));
        self::assertFalse($desligada->podeExcluir($this->tarefa, $primario, $this->tenant));
        self::assertFalse($ligada->podeExcluir($this->tarefa, $this->criador, $this->tenant));
        self::assertNull($ligada->seloDaExclusao($this->tarefa, $this->criador, $this->tenant));
    }

    #[TestDox('Edição sem prazo: o Master não exclui meta de outro escritório (a guarda de tenant continua)')]
    public function testMasterNaoPassaDoTenant(): void
    {
        $master = (new User())->setEmail('master@test.com')->setRoles(['ROLE_SUPER_ADMIN']);

        self::assertFalse($this->useCase->podeExcluir($this->tarefa, $master, new Tenant()));
    }
}
