<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Prévias da folha, pela conferência ✓/✗ de 10/11/2026 com o desenho 17 (commit bc30041ae), lidas no JS como texto:
 * não há navegador no PHPUnit. Ver a folha desenhada é smoke do dono.
 */
#[CoversNothing]
final class PreviaDaFolhaJsTest extends TestCase
{
    private const JS = __DIR__ . '/../../../public/js/';

    #[TestDox('Prévia do detalhe: sob a linha vai o nome cadastrado (desenho L352, b.r || s.nome); o digitado fica na lista')]
    public function testPreviaDoDetalheComONomeCadastrado(): void
    {
        $js = (string) file_get_contents(self::JS . 'assinatura.js');

        self::assertStringContainsString('sign: d.signatarios || []', $js);
        self::assertStringNotContainsString('{ nome: nomeDoSignatario(x) }', $js, 'o nome digitado não vai para a folha');
        self::assertStringContainsString("esc(nomeDoSignatario(x))", $js, 'a lista de signatários continua com o nome digitado (C5)');
    }

    #[TestDox('Duas colunas só com bloco: o editor vazio mostra a folha inteira, como o desenho (L322, L352)')]
    public function testDuasColunasSoComBloco(): void
    {
        $folha = (string) file_get_contents(self::JS . 'assinatura-folha.js');

        self::assertStringContainsString("if (o.layout === '2col' && bl.length) {", $folha);
        self::assertStringNotContainsString("if (o.layout === '2col') {", $folha);
    }
}
