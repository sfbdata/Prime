<?php

declare(strict_types=1);

namespace App\Tests\Tarefa\Unit;

use App\Entity\Tarefa\TarefaMensagem;
use App\Tarefa\Enum\AcaoDoDespacho;
use App\Tarefa\Enum\NaturezaDoDespacho;
use App\Tarefa\Exception\DespachoRegistradoException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** D1: despacho registrado (com código) não se edita; mensagem antiga, sem código, continua editável. */
#[CoversClass(TarefaMensagem::class)]
final class EditarDespachoRegistradoTest extends TestCase
{
    #[TestDox('Despacho com código não se edita; mensagem antiga, sem código, sim (a regra fica na entidade)')]
    public function testSoAAntigaPodeSerEditada(): void
    {
        self::assertFalse((new TarefaMensagem())->setMensagem('<p>Original</p>')->registrar('1234.5678', 'Ana', null)->podeSerEditado());
        self::assertTrue((new TarefaMensagem())->setMensagem('Original')->podeSerEditado());
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
