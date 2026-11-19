<?php

declare(strict_types=1);

namespace App\Tests\Contribua\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * A aba da campanha fica na posição do desenho (`aba()` do `bj-campanha.js`): pendurada no canto direito, logo abaixo
 * da barra azul do sistema (#mainHeader). Sem achar a barra, ela ia para o topo, por cima do nome e do avatar.
 */
#[CoversNothing]
final class AbaDaCampanhaTest extends TestCase
{
    private const CSS = __DIR__ . '/../../../public/css/contribua-campanha.css';
    private const JS = __DIR__ . '/../../../public/js/contribua-campanha.js';

    #[TestDox('CSS da aba: right:24px, sem centralizar')]
    public function testPosicaoDoDesenho(): void
    {
        preg_match('/^\.cp-aba \{[^}]*\}/m', (string) file_get_contents(self::CSS), $regra);

        self::assertNotEmpty($regra);
        self::assertStringContainsString('right: 24px;', $regra[0]);
        self::assertStringNotContainsString('left: 50%', $regra[0]);
        self::assertStringNotContainsString('translateX', $regra[0]);
        self::assertStringContainsString('box-shadow: 0 6px 16px rgba(0, 0, 0, .28);', $regra[0]);
    }

    #[TestDox('JS da aba: procura a barra #mainHeader antes do seletor do desenho e mantém o sumir em 5 s')]
    public function testJsProcuraABarraDoSistemaPrimeiro(): void
    {
        $js = (string) file_get_contents(self::JS);
        $barra = strpos($js, "document.getElementById('mainHeader')");
        $seletorDoDesenho = strpos($js, "querySelectorAll('header, [role=banner]");

        self::assertIsInt($barra);
        self::assertIsInt($seletorDoDesenho);
        self::assertLessThan($seletorDoDesenho, $barra);
        self::assertStringContainsString('const ABA_SOME_EM_MS = 5000;', $js);
    }
}
