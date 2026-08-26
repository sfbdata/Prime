<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Twig\DocumentoBrExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O banco guarda CPF e CNPJ de formas diferentes, e isso foi MEDIDO na PROD em
 * 2026-08-26, não suposto: dos 4 clientes PF, 3 têm CPF mascarado e 1 tem só os
 * 11 dígitos; dos 3 PJ, os 3 têm só os 14 dígitos e NENHUM tem máscara — a coluna
 * é varchar(14) e o CNPJ mascarado tem 18 caracteres, então ele nem caberia.
 *
 * Por isso a tela formata na exibição, e formata pela CONTAGEM DE DÍGITOS, como
 * o `telefone_br` já faz nesta casa.
 */
#[CoversClass(DocumentoBrExtension::class)]
final class DocumentoBrExtensionTest extends TestCase
{
    private DocumentoBrExtension $ext;

    protected function setUp(): void
    {
        $this->ext = new DocumentoBrExtension();
    }

    public static function casos(): array
    {
        return [
            'CPF só dígitos (1 cliente assim na prod)' => ['12345678901', '123.456.789-01'],
            'CPF já mascarado (3 clientes assim)'      => ['123.456.789-01', '123.456.789-01'],
            'CNPJ só dígitos (os 3 PJ da prod)'        => ['12345678000190', '12.345.678/0001-90'],
            'CNPJ já mascarado'                        => ['12.345.678/0001-90', '12.345.678/0001-90'],
            'CPF com espaços em volta'                 => ['  12345678901  ', '123.456.789-01'],
        ];
    }

    #[DataProvider('casos')]
    #[TestDox('formata pelo número de dígitos, venha mascarado ou cru')]
    public function testFormata(string $entrada, string $esperado): void
    {
        self::assertSame($esperado, $this->ext->documentoBr($entrada));
    }

    public static function naoMexe(): array
    {
        return [
            'vazio'                    => [''],
            'só espaço'                => ['   '],
            'curto demais'             => ['123'],
            'entre CPF e CNPJ'         => ['123456789012'],
            'longo demais'             => ['123456789012345'],
            'lixo de importação'       => ['nao informado'],
        ];
    }

    #[DataProvider('naoMexe')]
    #[TestDox('o que não é CPF nem CNPJ volta EXATAMENTE como veio')]
    public function testNaoMascaraOQueNaoBate(string $entrada): void
    {
        // Mascarar o que não bate exigiria inventar ou descartar dígito, e um
        // documento errado com máscara bonita é pior que o errado à vista: ele
        // deixa de parecer errado.
        self::assertSame($entrada, $this->ext->documentoBr($entrada));
    }

    #[TestDox('nulo vira string vazia, para o template não imprimir a palavra null')]
    public function testNuloViraVazio(): void
    {
        self::assertSame('', $this->ext->documentoBr(null));
    }

    #[TestDox('rotula pelo número de dígitos: 11 é CPF, 14 é CNPJ')]
    public function testRotulo(): void
    {
        self::assertSame('CPF', $this->ext->documentoBrRotulo('123.456.789-01'));
        self::assertSame('CPF', $this->ext->documentoBrRotulo('12345678901'));
        self::assertSame('CNPJ', $this->ext->documentoBrRotulo('12345678000190'));
        self::assertSame('CNPJ', $this->ext->documentoBrRotulo('12.345.678/0001-90'));
        self::assertSame('Documento', $this->ext->documentoBrRotulo('123'), 'sem certeza, não afirma o tipo');
    }
}
