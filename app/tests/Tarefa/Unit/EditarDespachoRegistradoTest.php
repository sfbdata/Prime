<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Entity\Tarefa\TarefaMensagem;
use App\Tarefa\UseCase\EditarTarefaMensagemUseCase;
use App\Tests\Shared\CriaSanitizadorTextoRico;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** D1: despacho registrado (com código) não se edita; mensagem antiga, sem código, continua editável. */
#[CoversClass(EditarTarefaMensagemUseCase::class)]
#[CoversClass(TarefaMensagem::class)]
final class EditarDespachoRegistradoTest extends TestCase
{
    use CriaSanitizadorTextoRico;

    #[TestDox('Despacho com código: recusa "Despacho registrado não pode ser editado." e nada muda')]
    public function testRegistradoNaoEdita(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');
        $despacho = (new TarefaMensagem())->setMensagem('<p>Original</p>')->registrar('1234.5678', 'Ana', null);

        try {
            (new EditarTarefaMensagemUseCase($em, $this->criarSanitizadorTextoRico()))->executar($despacho, 'Outro texto');
            self::fail('Deveria recusar.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Despacho registrado não pode ser editado.', $e->getMessage());
        }
        self::assertSame('<p>Original</p>', $despacho->getMensagem());
        self::assertNull($despacho->getEditadoEm());
    }

    #[TestDox('Mensagem antiga, sem código, continua editável')]
    public function testAntigaEdita(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');
        $mensagem = (new TarefaMensagem())->setMensagem('Original');

        (new EditarTarefaMensagemUseCase($em, $this->criarSanitizadorTextoRico()))->executar($mensagem, 'Novo');

        self::assertSame('Novo', $mensagem->getMensagem());
        self::assertTrue($mensagem->podeSerEditado());
    }

    #[TestDox('O registro congela nome e cargo e só é dado uma vez')]
    public function testRegistroUmaVez(): void
    {
        $despacho = (new TarefaMensagem())->registrar('1234.5678', '  Ana Souza ', ' ');

        self::assertSame('Ana Souza', $despacho->getRegistradoPorNome());
        self::assertNull($despacho->getRegistradoPorCargo());
        $this->expectException(\LogicException::class);
        $despacho->registrar('9999.9999', 'Outra', 'Cargo');
    }
}
