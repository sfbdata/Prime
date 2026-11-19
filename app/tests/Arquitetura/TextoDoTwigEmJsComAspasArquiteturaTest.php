<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * O filtro `|e('js')` escapa o texto, mas não põe as aspas do literal JS. Sem elas,
 * `onsubmit="return confirm({{ '...'|trans|e('js') }})"` vira `confirm(Excluir este...)`: erro de sintaxe,
 * o navegador descarta o handler e o formulário envia sem pedir confirmação. Isso aconteceu na extração
 * do texto fixo para o inglês. O certo é `confirm('{{ ...|e('js') }}')` ou `|json_encode|raw` dentro de <script>.
 */
#[CoversNothing]
final class TextoDoTwigEmJsComAspasArquiteturaTest extends TestCase
{
    private const TEMPLATES = __DIR__ . '/../../templates';

    #[TestDox("confirm/alert/prompt com {{ ...|e('js') }} sempre entre aspas")]
    public function testTextoEscapadoParaJsVemEntreAspas(): void
    {
        $semAspas = [];
        foreach ((new Finder())->files()->in(self::TEMPLATES)->name('*.twig') as $arquivo) {
            $linhas = explode("\n", $arquivo->getContents());
            foreach ($linhas as $i => $linha) {
                if (preg_match('/\b(confirm|alert|prompt)\(\s*\{\{(?:(?!\}\}).)*\|e\([\'"]js[\'"]\)/', $linha) === 1) {
                    $semAspas[] = $arquivo->getRelativePathname() . ':' . ($i + 1);
                }
            }
        }

        self::assertSame([], $semAspas, "Texto do Twig dentro de confirm/alert/prompt sem as aspas do literal JS:\n" . implode("\n", $semAspas));
    }
}
