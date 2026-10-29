<?php

declare(strict_types=1);

namespace App\Tests\Central\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/** Guarda do mini chat da IA na Central (docs/specs/chat-ia-mini-central.md): só textContent e o evento antigo preservado. */
final class MiniChatDaIaJsTest extends TestCase
{
    private function ler(string $arquivo): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 3) . '/public/js/' . $arquivo);
    }

    #[TestDox('O mini chat nunca monta HTML a partir do texto do servidor')]
    public function testSemHtmlDoServidor(): void
    {
        $js = $this->ler('central-ia-chat.js');

        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'eval('] as $proibido) {
            self::assertStringNotContainsString($proibido, $js, $proibido);
        }
        self::assertStringContainsString('textContent', $js);
        self::assertSame(0, preg_match('/[—–]/u', $js), 'sem travessão no texto do usuário');
    }

    #[TestDox('A Central aciona o mini no lugar do caminho antigo e mantém o evento bj-central:perguntar-ia')]
    public function testCentralAcionaOMini(): void
    {
        $central = $this->ler('central.js');
        $flutuante = $this->ler('central-flutuante.js');

        self::assertStringContainsString('window.BJMiniIaChat.montar', $central);
        self::assertStringContainsString("'bj-central:janela-perguntar-ia'", $central);
        self::assertStringContainsString("'bj-central:perguntar-ia'", $flutuante);
    }

    #[TestDox('O mini usa o CSRF, as rotas do Chat I.A e pede a origem central ao criar')]
    public function testRotasEOrigem(): void
    {
        $js = $this->ler('central-ia-chat.js');

        self::assertStringContainsString("'X-CSRF-Token'", $js);
        self::assertStringContainsString("{ origem: 'central' }", $js);
        self::assertStringContainsString('ROTAS.importar', $js);
    }
}
