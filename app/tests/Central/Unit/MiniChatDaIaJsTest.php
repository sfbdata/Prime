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

    #[TestDox('A resposta da IA no mini usa o MESMO renderizador de markdown da página inteira (nada de **negrito** cru)')]
    public function testMiniUsaORenderizadorCompartilhado(): void
    {
        $mini = $this->ler('central-ia-chat.js');
        $pagina = $this->ler('chat-ia.js');
        $comum = $this->ler('chat-ia-markdown.js');

        self::assertStringContainsString('window.BJMarkdown.criar', $mini);
        self::assertStringContainsString('window.BJMarkdown.criar', $pagina);
        self::assertStringContainsString('window.BJMarkdown = { criar: criar }', $comum);
        self::assertStringNotContainsString('function renderizarMarkdown', $pagina, 'o renderizador mora só em chat-ia-markdown.js');
        self::assertStringContainsString('renderizarMarkdown(recebido, alvo)', $mini, 'cada trecho do fluxo é renderizado');
        self::assertDoesNotMatchRegularExpression('/\.innerHTML|insertAdjacentHTML/', $comum);
        foreach (['central/_flutuante.html.twig', 'central/index.html.twig', 'tarefa/minhas.html.twig', 'chat_ia/pagina.html.twig'] as $template) {
            self::assertStringContainsString('js/chat-ia-markdown.js', (string) file_get_contents(\dirname(__DIR__, 3) . '/templates/' . $template), $template);
        }
    }

    #[TestDox('O painel de Minhas Metas responde no próprio painel, manda só o sinal contexto metas e mantém o evento antigo como reserva')]
    public function testPainelDeMetasRespondeNoProprioPainel(): void
    {
        $metas = $this->ler('minhas-metas-ia.js');
        $mini = $this->ler('central-ia-chat.js');

        self::assertStringContainsString('window.BJMiniIaChat.montar', $metas);
        self::assertStringContainsString("contexto: 'metas'", $metas);
        self::assertStringContainsString('compacto: true', $metas);
        self::assertStringContainsString("'bj-central:perguntar-ia'", $metas, 'reserva sem o mini carregado');
        self::assertStringContainsString('corpoEnvio.contexto = cfg.contexto', $mini);
        self::assertStringNotContainsString('definir(false);\n            chat', $metas);
        $painel = (string) file_get_contents(\dirname(__DIR__, 3) . '/templates/tarefa/_ia_painel.html.twig');
        self::assertStringContainsString('data-mm-ia-resposta', $painel);
    }
}
