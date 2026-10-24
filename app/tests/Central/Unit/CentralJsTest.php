<?php

declare(strict_types=1);

namespace App\Tests\Central\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * B1 (HANDOFF §11.3): a Central não usa as caixas nativas do navegador. Confirmação e aviso são os do desenho,
 * dentro da janela (o window.confirm/alert também travaria a janela destacada do B2). Lido como folha: o
 * comportamento na tela é provado no Playwright comparativo (e2e/tests/central-fidelidade.spec.js).
 */
final class CentralJsTest extends TestCase
{
    private const DIR = __DIR__ . '/../../../public/js/';

    /** @return iterable<string, array{string}> */
    public static function arquivos(): iterable
    {
        foreach (['central.js', 'central-flutuante.js', 'central-dica.js'] as $arquivo) {
            yield $arquivo => [$arquivo];
        }
    }

    #[DataProvider('arquivos')]
    #[TestDox('$arquivo: nenhum alert/confirm/prompt nativo e nenhum innerHTML')]
    public function testSemCaixaNativaNemInnerHtml(string $arquivo): void
    {
        $js = (string) file_get_contents(self::DIR . $arquivo);
        self::assertNotSame('', $js);
        self::assertDoesNotMatchRegularExpression('/\b(window\.)?(alert|confirm|prompt)\s*\(/', $js);
        self::assertDoesNotMatchRegularExpression('/\.(innerHTML|outerHTML)\s*=|insertAdjacentHTML|document\.write/', $js);
    }

    #[TestDox('central.js: conversa 1:1 fora da lista (excluída da minha caixa) não vira "Você não participa mais"; só o grupo')]
    public function testConversaExcluidaNaoBloqueiaO1a1(): void
    {
        $js = (string) file_get_contents(self::DIR . 'central.js');
        self::assertMatchesRegularExpression('/function sincronizarAberta\(\)[\s\S]*?if \(!atual\) \{\s*estado\.aberta\.fora = estado\.aberta\.grupo;/', $js);
        self::assertStringContainsString("'Você não participa mais desta conversa.'", $js, 'o aviso segue para quem saiu do grupo');
    }
}
