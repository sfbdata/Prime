<?php

declare(strict_types=1);

namespace App\Tests\Shared\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Regra do dono (15/10/2026): nenhum texto visível usa travessão. O título da aba do navegador é
 * o primeiro lugar em que isso aparece ("Entrar — BlueJus" na tela de login), então fica travado aqui.
 */
#[CoversNothing]
final class TituloDeAbaSemTravessaoTest extends TestCase
{
    #[TestDox('Nenhum template usa travessão no título da aba do navegador')]
    public function testNenhumTituloDeAbaUsaTravessao(): void
    {
        $violacoes = [];
        $templates = (new Finder())->files()->in(__DIR__ . '/../../../templates')->name('*.twig');

        foreach ($templates as $arquivo) {
            preg_match_all('/\{%\s*block\s+title\s*%\}.*?\{%\s*endblock\s*%\}|<title>.*?<\/title>/s', $arquivo->getContents(), $titulos);

            foreach ($titulos[0] as $titulo) {
                if (preg_match('/[—–]/u', $titulo)) {
                    $violacoes[] = $arquivo->getRelativePathname() . ': ' . trim($titulo);
                }
            }
        }

        self::assertSame([], $violacoes, 'Use " | " no lugar do travessão no título da aba.');
    }
}
