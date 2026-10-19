<?php

declare(strict_types=1);

namespace App\Tests\Kanban\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Kanban\DTO\AtualizarBoardInput;
use App\Kanban\Entity\KanbanBoard;
use App\Kanban\UseCase\AtualizarBoardUseCase;
use App\Repository\UserRepository;
use App\Tenant\Service\ColaboradoresAtribuiveis;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(AtualizarBoardUseCase::class)]
final class AtualizarBoardUseCaseTest extends TestCase
{
    #[TestDox('editar o mural mantém a participante suspensa que já estava e não aceita uma suspensa nova')]
    public function testPreservaParticipanteSuspensa(): void
    {
        $tenant = new Tenant();
        [$criador, $ana, $carla] = [$this->usuario(1), $this->usuario(2), $this->usuario(3)];
        $board = new KanbanBoard('Mural', $tenant, $criador);
        $board->adicionarParticipante($carla);

        $repo = $this->createStub(UserRepository::class);
        $repo->method('findColaboradorAtivoPorIdETenant')->willReturnCallback(
            static fn (int $id): ?User => $id === 2 ? $ana : null,
        );

        $input                   = new AtualizarBoardInput();
        $input->nome             = 'Mural';
        $input->participantesIds = [2, 3, 4];
        (new AtualizarBoardUseCase(new ColaboradoresAtribuiveis($repo)))->executar($board, $input, $criador, $tenant);

        $ids = array_map(static fn (User $u): ?int => $u->getId(), $board->getParticipantes()->toArray());
        sort($ids);
        self::assertSame([2, 3], $ids);
    }

    private function usuario(int $id): User
    {
        $u = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($u, $id);

        return $u;
    }
}
