<?php

declare(strict_types=1);

namespace App\Tests\Kanban\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Kanban\DTO\AtualizarBoardInput;
use App\Kanban\DTO\CriarBoardInput;
use App\Kanban\Entity\KanbanBoard;
use App\Kanban\Repository\KanbanBoardRepository;
use App\Kanban\Repository\KanbanColunaRepository;
use App\Kanban\UseCase\AtualizarBoardUseCase;
use App\Kanban\UseCase\CriarBoardUseCase;
use App\Repository\UserRepository;
use App\Tenant\Service\ColaboradoresAtribuiveis;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(AtualizarBoardUseCase::class)]
#[CoversClass(CriarBoardUseCase::class)]
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

    #[TestDox('criar mural: a suspensa enviada pelo navegador não entra como participante')]
    public function testCriarRecusaSuspensa(): void
    {
        $tenant = new Tenant();
        [$criador, $ana] = [$this->usuario(1), $this->usuario(2)];
        $repo = $this->createStub(UserRepository::class);
        $repo->method('findColaboradorAtivoPorIdETenant')->willReturnCallback(static fn (int $id): ?User => $id === 2 ? $ana : null);
        $capturado = null;
        $boards    = $this->createStub(KanbanBoardRepository::class);
        $boards->method('salvar')->willReturnCallback(static function (KanbanBoard $b) use (&$capturado): void {
            $capturado = $b;
            (new \ReflectionProperty(KanbanBoard::class, 'id'))->setValue($b, 1);
        });

        $input                   = new CriarBoardInput();
        $input->nome             = 'Mural';
        $input->participantesIds = [2, 3];
        (new CriarBoardUseCase($boards, $this->createStub(KanbanColunaRepository::class), new ColaboradoresAtribuiveis($repo), $this->createStub(EntityManagerInterface::class)))
            ->executar($input, $criador, $tenant);

        self::assertInstanceOf(KanbanBoard::class, $capturado);
        self::assertSame([2], array_map(static fn (User $u): ?int => $u->getId(), $capturado->getParticipantes()->toArray()));
    }

    private function usuario(int $id): User
    {
        $u = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($u, $id);

        return $u;
    }
}
