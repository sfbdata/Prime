<?php

declare(strict_types=1);

namespace App\Tests\Master\Unit\Central;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * O texto da Central do Master vem de fora (visitante da tela de entrada, autor de sugestão): o JS da seção só escreve
 * por textContent, nunca monta HTML com o dado (spec master-central-de-notificacoes.md; XSS).
 */
#[CoversNothing]
final class NotificacoesJsTest extends TestCase
{
    #[TestDox('notificacoes.js não usa innerHTML, outerHTML, insertAdjacentHTML nem document.write')]
    public function testSemHtmlMontadoComDado(): void
    {
        $js = (string) file_get_contents(__DIR__ . '/../../../../public/js/master/notificacoes.js');
        $semComentarios = (string) preg_replace('#^\s*//.*$#m', '', (string) preg_replace('#/\*.*?\*/#s', '', $js));

        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write'] as $proibido) {
            self::assertStringNotContainsString($proibido, $semComentarios, $proibido);
        }
        self::assertStringContainsString('textContent', $semComentarios);
    }
}
