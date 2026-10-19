<?php

declare(strict_types=1);

namespace App\Tests\Auth\Unit;

use App\Auth\DTO\EnviarMensagemSuporteInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(EnviarMensagemSuporteInput::class)]
final class EnviarMensagemSuporteInputTest extends TestCase
{
    #[TestDox('Apara os campos e guarda IP e navegador')]
    public function testAparaCampos(): void
    {
        $input = EnviarMensagemSuporteInput::doPayload(
            ['nome' => '  Ana Souza ', 'email' => ' ana@adv.com ', 'assunto' => 'Esqueci a senha', 'mensagem' => "  Não lembro.\nAjuda  "],
            '10.0.0.1',
            'Navegador/1.0',
        );

        self::assertSame('Ana Souza', $input->nome);
        self::assertSame('ana@adv.com', $input->email);
        self::assertSame('Esqueci a senha', $input->assunto);
        self::assertSame("Não lembro.\nAjuda", $input->mensagem);
        self::assertSame('10.0.0.1', $input->ip);
        self::assertSame('Navegador/1.0', $input->userAgent);
    }

    /** @return iterable<string, array{mixed}> */
    public static function assuntosForaDaLista(): iterable
    {
        yield 'vazio' => [''];
        yield 'inventado' => ['Quero desconto'];
        yield 'caixa diferente' => ['esqueci a senha'];
        yield 'não texto' => [['Criar conta']];
        yield 'ausente' => [null];
    }

    #[DataProvider('assuntosForaDaLista')]
    #[TestDox('Assunto fora dos quatro do desenho vira nulo')]
    public function testAssuntoForaDaListaViraNulo(mixed $assunto): void
    {
        $input = EnviarMensagemSuporteInput::doPayload(['assunto' => $assunto], '', null);

        self::assertNull($input->assunto);
    }

    #[TestDox('Os quatro assuntos do desenho passam')]
    public function testAssuntosDoDesenho(): void
    {
        foreach (EnviarMensagemSuporteInput::ASSUNTOS as $assunto) {
            self::assertSame($assunto, EnviarMensagemSuporteInput::doPayload(['assunto' => $assunto], '', null)->assunto);
        }
    }

    #[TestDox('Corta nome em 120, e-mail em 180 e mensagem em 5000 caracteres')]
    public function testCortaNoTamanhoDasColunas(): void
    {
        $input = EnviarMensagemSuporteInput::doPayload([
            'nome' => str_repeat('á', 130),
            'email' => str_repeat('e', 200) . '@x.com',
            'mensagem' => str_repeat('ç', 6000),
        ], '', null);

        self::assertSame(120, mb_strlen($input->nome));
        self::assertSame(180, mb_strlen($input->email));
        self::assertSame(5000, mb_strlen($input->mensagem));
    }

    #[TestDox('Campo que não é texto vira vazio')]
    public function testCampoNaoTextoViraVazio(): void
    {
        $input = EnviarMensagemSuporteInput::doPayload(['nome' => ['x'], 'email' => ['a@b.c'], 'mensagem' => null], '', null);

        self::assertSame('', $input->nome);
        self::assertSame('', $input->email);
        self::assertSame('', $input->mensagem);
    }
}
