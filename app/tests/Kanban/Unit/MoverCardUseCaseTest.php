<?php

declare(strict_types=1);

namespace App\Tests\Kanban\Unit;

use App\Entity\Auth\User;
use App\Kanban\DTO\MoverCardInput;
use App\Kanban\Entity\KanbanBoard;
use App\Kanban\Entity\KanbanCard;
use App\Kanban\Entity\KanbanColuna;
use App\Kanban\Repository\KanbanCardRepository;
use App\Kanban\Repository\KanbanColunaRepository;
use App\Kanban\UseCase\MoverCardUseCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[CoversClass(MoverCardUseCase::class)]
final class MoverCardUseCaseTest extends TestCase
{
    public function testDeveMoverCardParaNovaColunaDoMesmoBoard(): void
    {
        $usuario    = $this->createStub(User::class);
        $board      = $this->criarBoard($usuario);
        $colunaOrig = $this->criarColuna($board, 1);
        $colunaDest = $this->criarColuna($board, 2);
        $card       = $this->criarCard($colunaOrig, $board);

        $card->expects($this->once())->method('setColuna')->with($colunaDest);
        $card->expects($this->once())->method('setPosicao')->with(0);

        $cardRepo = $this->createMock(KanbanCardRepository::class);
        $cardRepo->expects($this->once())->method('salvar')->with($card, flush: true);

        $colunaRepo = $this->createStub(KanbanColunaRepository::class);
        $colunaRepo->method('findPorBoardEId')->willReturn($colunaDest);

        $input   = new MoverCardInput(novaColunaId: 2, novaPosicao: 0);
        $useCase = new MoverCardUseCase($cardRepo, $colunaRepo);
        $useCase->executar($card, $input, $usuario);
    }

    public function testDeveLancarExcecaoSeUsuarioSemAcesso(): void
    {
        $participante = $this->createStub(User::class);
        $outroUsuario = $this->createStub(User::class);
        $board        = $this->criarBoard($participante);
        $coluna       = $this->criarColuna($board, 1);
        $card         = $this->criarCard($coluna, $board);

        $cardRepo   = $this->createStub(KanbanCardRepository::class);
        $colunaRepo = $this->createStub(KanbanColunaRepository::class);

        $input   = new MoverCardInput(novaColunaId: 1, novaPosicao: 0);
        $useCase = new MoverCardUseCase($cardRepo, $colunaRepo);

        $this->expectException(AccessDeniedException::class);
        $useCase->executar($card, $input, $outroUsuario);
    }

    public function testDeveLancarExcecaoSeColunaInvalida(): void
    {
        $usuario = $this->createStub(User::class);
        $board   = $this->criarBoard($usuario);
        $coluna  = $this->criarColuna($board, 1);
        $card    = $this->criarCard($coluna, $board);

        $cardRepo = $this->createStub(KanbanCardRepository::class);

        $colunaRepo = $this->createStub(KanbanColunaRepository::class);
        $colunaRepo->method('findPorBoardEId')->willReturn(null);

        $input   = new MoverCardInput(novaColunaId: 999, novaPosicao: 0);
        $useCase = new MoverCardUseCase($cardRepo, $colunaRepo);

        $this->expectException(\InvalidArgumentException::class);
        $useCase->executar($card, $input, $usuario);
    }

    private function criarBoard(User $criador): KanbanBoard
    {
        $board = $this->getMockBuilder(KanbanBoard::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['temAcesso'])
            ->getMock();
        $board->method('temAcesso')->willReturnCallback(fn(User $u) => $u === $criador);

        return $board;
    }

    private function criarColuna(KanbanBoard $board, int $id): KanbanColuna
    {
        $coluna = $this->getMockBuilder(KanbanColuna::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getBoard'])
            ->getMock();
        $coluna->method('getBoard')->willReturn($board);

        return $coluna;
    }

    private function criarCard(KanbanColuna $coluna, KanbanBoard $board): KanbanCard
    {
        $card = $this->getMockBuilder(KanbanCard::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getBoard', 'getColuna', 'setColuna', 'setPosicao'])
            ->getMock();
        $card->method('getBoard')->willReturn($board);
        $card->method('getColuna')->willReturn($coluna);

        return $card;
    }
}
