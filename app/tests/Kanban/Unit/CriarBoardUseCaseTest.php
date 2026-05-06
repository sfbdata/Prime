<?php

declare(strict_types=1);

namespace App\Tests\Kanban\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Kanban\DTO\CriarBoardInput;
use App\Kanban\Entity\KanbanBoard;
use App\Kanban\Entity\KanbanColuna;
use App\Kanban\Repository\KanbanBoardRepository;
use App\Kanban\Repository\KanbanColunaRepository;
use App\Kanban\UseCase\CriarBoardUseCase;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CriarBoardUseCase::class)]
final class CriarBoardUseCaseTest extends TestCase
{
    public function testDeveCriarBoardComQuatroColunasPadrao(): void
    {
        $tenant  = $this->createStub(Tenant::class);
        $criador = $this->createStub(User::class);
        $criador->method('getTenant')->willReturn($tenant);

        $boardSalvos  = [];
        $colunasSalvas = [];

        $boardRepo = $this->createMock(KanbanBoardRepository::class);
        $boardRepo->expects($this->once())->method('salvar')
            ->willReturnCallback(function (KanbanBoard $b) use (&$boardSalvos) {
                $boardSalvos[] = $b;
                $prop = new \ReflectionProperty(KanbanBoard::class, 'id');
                $prop->setValue($b, 1);
            });

        $colunaRepo = $this->createMock(KanbanColunaRepository::class);
        $colunaRepo->expects($this->exactly(4))->method('salvar')
            ->willReturnCallback(function (KanbanColuna $c) use (&$colunasSalvas) {
                $colunasSalvas[] = $c;
            });

        $userRepo = $this->createStub(UserRepository::class);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $input        = new CriarBoardInput();
        $input->nome  = 'Projetos 2026';

        $useCase = new CriarBoardUseCase($boardRepo, $colunaRepo, $userRepo, $em);
        $useCase->executar($input, $criador);

        self::assertCount(4, $colunasSalvas);

        $tipos = array_map(fn(KanbanColuna $c) => $c->getTipo(), $colunasSalvas);
        self::assertContains(KanbanColuna::TIPO_PENDENCIAS, $tipos);
        self::assertContains(KanbanColuna::TIPO_A_FAZER, $tipos);
        self::assertContains(KanbanColuna::TIPO_FAZENDO, $tipos);
        self::assertContains(KanbanColuna::TIPO_FEITO, $tipos);
    }

    public function testDeveCriarBoardComNomeCorreto(): void
    {
        $tenant  = $this->createStub(Tenant::class);
        $criador = $this->createStub(User::class);
        $criador->method('getTenant')->willReturn($tenant);

        $boardCapturado = null;

        $boardRepo = $this->createMock(KanbanBoardRepository::class);
        $boardRepo->method('salvar')
            ->willReturnCallback(function (KanbanBoard $b) use (&$boardCapturado) {
                $boardCapturado = $b;
                $prop = new \ReflectionProperty(KanbanBoard::class, 'id');
                $prop->setValue($b, 1);
            });

        $colunaRepo = $this->createStub(KanbanColunaRepository::class);
        $userRepo   = $this->createStub(UserRepository::class);
        $em         = $this->createMock(EntityManagerInterface::class);
        $em->method('flush');

        $input       = new CriarBoardInput();
        $input->nome = 'Mural de Tarefas';
        $input->cor  = '#3b82f6';

        $useCase = new CriarBoardUseCase($boardRepo, $colunaRepo, $userRepo, $em);
        $useCase->executar($input, $criador);

        self::assertNotNull($boardCapturado);
        self::assertSame('Mural de Tarefas', $boardCapturado->getNome());
        self::assertSame('#3b82f6', $boardCapturado->getCor());
    }
}
