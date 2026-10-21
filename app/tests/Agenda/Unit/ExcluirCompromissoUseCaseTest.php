<?php

declare(strict_types=1);

namespace App\Tests\Agenda\Unit;

use App\Agenda\Entity\Evento;
use App\Agenda\Exception\CompromissoNaoEncontradoException;
use App\Agenda\Exception\SemPermissaoNoCompromissoException;
use App\Agenda\Repository\EventoDaAgendaRepository;
use App\Agenda\UseCase\ExcluirCompromissoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExcluirCompromissoUseCase::class)]
final class ExcluirCompromissoUseCaseTest extends TestCase
{
    #[TestDox('O criador exclui')]
    public function testCriadorExclui(): void
    {
        $eu = $this->usuario(1);
        $evento = (new Evento())->setCriador($eu);
        $repo = $this->createMock(EventoDaAgendaRepository::class);
        $repo->method('visivelPara')->willReturn($evento);
        $repo->expects(self::once())->method('remover')->with($evento);

        (new ExcluirCompromissoUseCase($repo, $this->createStub(PermissionChecker::class)))->executar(5, $eu, new Tenant());
    }

    #[TestDox('Colega sem administrar usuários não exclui; administrador exclui')]
    public function testColegaEAdministrador(): void
    {
        $evento = (new Evento())->setCriador($this->usuario(2));
        $repo = $this->createMock(EventoDaAgendaRepository::class);
        $repo->method('visivelPara')->willReturn($evento);
        $repo->expects(self::once())->method('remover');
        $permissoes = $this->createStub(PermissionChecker::class);
        $permissoes->method('canAdminister')->willReturnOnConsecutiveCalls(false, true);
        $sut = new ExcluirCompromissoUseCase($repo, $permissoes);

        try {
            $sut->executar(5, $this->usuario(1), new Tenant());
            self::fail('Devia recusar');
        } catch (SemPermissaoNoCompromissoException) {
        }
        $sut->executar(5, $this->usuario(1), new Tenant());
    }

    #[TestDox('Compromisso que não vejo dá "não encontrado" e nada é excluído')]
    public function testInvisivel(): void
    {
        $repo = $this->createMock(EventoDaAgendaRepository::class);
        $repo->method('visivelPara')->willReturn(null);
        $repo->expects(self::never())->method('remover');
        $this->expectException(CompromissoNaoEncontradoException::class);

        (new ExcluirCompromissoUseCase($repo, $this->createStub(PermissionChecker::class)))->executar(5, $this->usuario(1), new Tenant());
    }

    private function usuario(int $id): User
    {
        $u = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($u, $id);

        return $u;
    }
}
