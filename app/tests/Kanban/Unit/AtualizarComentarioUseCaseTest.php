<?php

declare(strict_types=1);

namespace App\Tests\Kanban\Unit;

use App\Entity\Auth\User;
use App\Kanban\DTO\CriarComentarioInput;
use App\Kanban\Entity\KanbanComentario;
use App\Kanban\Repository\KanbanComentarioRepository;
use App\Kanban\UseCase\AtualizarComentarioUseCase;
use App\Tests\Kanban\CriaSanitizadorTextoKanban;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[CoversClass(AtualizarComentarioUseCase::class)]
final class AtualizarComentarioUseCaseTest extends TestCase
{
    use CriaSanitizadorTextoKanban;

    public function testDeveAtualizarComentarioDoProprioUsuario(): void
    {
        $usuario = $this->createStub(User::class);
        $usuario->method('getFullName')->willReturn('Autor Teste');
        $usuario->method('getEmail')->willReturn('autor@teste.com');

        $comentario = $this->createMock(KanbanComentario::class);
        $comentario->method('pertenceAo')->with($usuario)->willReturn(true);
        $comentario->method('getId')->willReturn(1);
        $comentario->method('getCriadoPor')->willReturn($usuario);
        $comentario->method('getCriadoEm')->willReturn(new \DateTimeImmutable());
        $comentario->expects($this->once())->method('setConteudo')->with('<p>novo</p>');
        $comentario->method('getConteudo')->willReturn('<p>novo</p>');

        $repo = $this->createMock(KanbanComentarioRepository::class);
        $repo->expects($this->once())->method('salvar')->with($comentario, flush: true);

        $input   = new CriarComentarioInput(conteudo: '<p>novo</p>');
        $useCase = new AtualizarComentarioUseCase($repo, $this->criarSanitizadorTextoKanban());
        $useCase->executar($comentario, $input, $usuario);
    }

    public function testDeveLancarExcecaoSeUsuarioNaoEDono(): void
    {
        $usuario    = $this->createStub(User::class);
        $outroUser  = $this->createStub(User::class);
        $comentario = $this->createStub(KanbanComentario::class);
        $comentario->method('pertenceAo')->willReturnCallback(fn(User $u) => $u === $outroUser);

        $repo    = $this->createStub(KanbanComentarioRepository::class);
        $input   = new CriarComentarioInput(conteudo: '<p>hack</p>');
        $useCase = new AtualizarComentarioUseCase($repo, $this->criarSanitizadorTextoKanban());

        $this->expectException(AccessDeniedException::class);
        $useCase->executar($comentario, $input, $usuario);
    }
}
