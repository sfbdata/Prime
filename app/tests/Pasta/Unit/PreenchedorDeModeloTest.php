<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use App\Pasta\Service\PreenchedorDeModelo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[CoversClass(PreenchedorDeModelo::class)]
#[Group('pasta')]
final class PreenchedorDeModeloTest extends TestCase
{
    #[TestDox('As chaves do corpo saem distintas e na ordem em que aparecem')]
    public function testChaves(): void
    {
        self::assertSame(['cliente', 'cpf', 'x_1'], PreenchedorDeModelo::chavesDo('<p>{cliente}, CPF {cpf}, {cliente} e {x_1}. {sem espaço}</p>'));
        self::assertSame([], PreenchedorDeModelo::chavesDo('<p>sem variável</p>'));
    }

    #[TestDox('Variável com dado é trocada; sem dado vira ___; chave desconhecida também')]
    public function testPreencheELacuna(): void
    {
        $html = (new PreenchedorDeModelo())->preencher('<p>CPF {cpf}, processo {processo}, {inventada}</p>', ['cpf' => '123.456.789-09', 'processo' => '  ']);

        self::assertSame('<p>CPF 123.456.789-09, processo ___, ___</p>', $html);
    }

    #[TestDox('O nome do cliente sai em negrito e maiúsculas, também quando já estava digitado no texto')]
    public function testNegritoDoCliente(): void
    {
        $html = (new PreenchedorDeModelo())->preencher('<p>{cliente} outorga a Maria da Silva</p>', ['cliente' => 'Maria da Silva']);

        self::assertSame('<p><b>MARIA DA SILVA</b> outorga a <b>MARIA DA SILVA</b></p>', $html);
    }

    #[TestDox('Dado da pasta nunca vira HTML: o valor entra escapado')]
    public function testEscapa(): void
    {
        $html = (new PreenchedorDeModelo())->preencher('<p>{cliente}</p>', ['cliente' => '<script>alert(1)</script>']);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;SCRIPT&gt;', $html);
    }

    #[TestDox('Cliente em lacuna não ganha negrito')]
    public function testSemClienteSemNegrito(): void
    {
        self::assertSame('<p>___</p>', (new PreenchedorDeModelo())->preencher('<p>{cliente}</p>', []));
    }

    #[TestDox('O negrito do cliente só entra no texto: nome curto igual a tag ou atributo não quebra o markup')]
    public function testNegritoNaoQuebraTag(): void
    {
        $html = (new PreenchedorDeModelo())->preencher('<p class="b" style="x">{cliente}: b e class</p>', ['cliente' => 'b']);

        self::assertSame('<p class="b" style="x"><b>B</b>: <b>B</b> e class</p>', $html);

        $html = (new PreenchedorDeModelo())->preencher('<p class="class">{cliente} class</p>', ['cliente' => 'class']);
        self::assertSame('<p class="class"><b>CLASS</b> <b>CLASS</b></p>', $html);
    }
}
