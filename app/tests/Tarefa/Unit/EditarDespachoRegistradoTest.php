<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Entity\Tarefa\TarefaMensagem;
use App\Tarefa\Enum\AcaoDoDespacho;
use App\Tarefa\Enum\NaturezaDoDespacho;
use App\Tarefa\Exception\DespachoRegistradoException;
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

    /**
     * C16: a trava mora na entidade. Texto, natureza, prazo e ação de um despacho registrado não mudam por nenhum
     * caminho; a exceção é um `\LogicException`, que o "Desfazer" da auditoria recusa sem quebrar.
     *
     * @return iterable<string, array{\Closure(TarefaMensagem): mixed}>
     */
    public static function setters(): iterable
    {
        yield 'mensagem' => [static fn (TarefaMensagem $d): mixed => $d->setMensagem('Outro')];
        yield 'natureza' => [static fn (TarefaMensagem $d): mixed => $d->setNatureza(NaturezaDoDespacho::Ciencia)];
        yield 'prazo' => [static fn (TarefaMensagem $d): mixed => $d->setPrazoCumprimento(new \DateTimeImmutable('2026-12-01'))];
        yield 'acao' => [static fn (TarefaMensagem $d): mixed => $d->setAcao(AcaoDoDespacho::Envio)];
    }

    /** @param \Closure(TarefaMensagem): mixed $mudar */
    #[\PHPUnit\Framework\Attributes\DataProvider('setters')]
    #[TestDox('Despacho registrado: o setter de $_dataName recusa com DespachoRegistradoException')]
    public function testSetterTravadoDepoisDoRegistro(\Closure $mudar): void
    {
        $despacho = (new TarefaMensagem())->setMensagem('<p>Original</p>')->setAcao(AcaoDoDespacho::Despacho)->registrar('1234.5678', 'Ana', null);

        try {
            $mudar($despacho);
            self::fail('Deveria recusar.');
        } catch (DespachoRegistradoException $e) {
            self::assertInstanceOf(\LogicException::class, $e);
            self::assertSame('Despacho registrado não pode ser editado.', $e->getMessage());
        }
        self::assertSame('<p>Original</p>', $despacho->getMensagem());
        self::assertSame(AcaoDoDespacho::Despacho, $despacho->getAcao());
    }
}
