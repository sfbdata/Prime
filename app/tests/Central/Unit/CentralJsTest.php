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

    #[TestDox('central.js F3: a conversa IA pergunta por /central/ia/perguntas, nunca por /mensagens, e a lista tolera `outro: null`')]
    public function testConversaDaIaUsaARotaDePergunta(): void
    {
        $js = (string) file_get_contents(self::DIR . 'central.js');
        self::assertStringContainsString('raiz.dataset.urlIaPergunta', $js);
        self::assertStringContainsString('raiz.dataset.urlIaStatus', $js);
        // enviar() desvia a conversa IA antes de montar o POST comum de mensagens
        self::assertMatchesRegularExpression('/if \(estado\.aberta\.ia\) \{ perguntarAIa\(texto\); return; \}[\s\S]*?urls\.base \+ \'\/\' \+ id \+ \'\/mensagens\'/', $js);
        self::assertMatchesRegularExpression('/function perguntarAIa\([\s\S]*?pedir\(urls\.iaPergunta, \{ metodo: \'POST\'/', $js);
        // `outro` só é lido com guarda
        self::assertStringContainsString('c.outro ? c.outro.nome', $js);
        self::assertStringContainsString('estado.aberta.outro ? membroDaEquipe', $js);
        self::assertStringContainsString('outro: c.outro || null', $js);
    }

    #[TestDox('central.js F3: deIa é testado antes de minha; "digitando..." some com a fala ou com o status terminal; um status por ciclo')]
    public function testFalaDaIaEDigitando(): void
    {
        $js = (string) file_get_contents(self::DIR . 'central.js');
        self::assertMatchesRegularExpression('/var deIa = m\.deIa === true;[\s\S]*?\(deIa \? \' bj-central-msg--ia\' : \(m\.minha/', $js);
        self::assertStringContainsString("m.deIa === true; })) { encerrarPerguntaDaIa(); } else { conferirPerguntaDaIa(); }", $js);
        self::assertStringContainsString('if (!pendente || estado.iaConsultando) { return; }', $js);
        self::assertStringContainsString("d.status === 'falhou' || d.status === 'indisponivel'", $js);
        self::assertStringContainsString('if (d.mensagem) { avisar(d.mensagem); }', $js);
        // sem segundo envio enquanto a pergunta está em andamento
        self::assertStringContainsString('estado.enviando || iaBloqueada()', $js);
        self::assertStringContainsString('el.campo.readOnly = iaBloqueada();', $js);
    }

    #[TestDox('central.js F3: o texto da conversa IA vem do data-ia-* (translations), não fixo; menu da IA sem Excluir nem Arquivar')]
    public function testTextoDaIaVemDoTwigEMenuEnxuto(): void
    {
        $js = (string) file_get_contents(self::DIR . 'central.js');
        foreach (['iaNome', 'iaSetor', 'iaFuncao', 'iaVazioTitulo', 'iaVazioTexto', 'iaDigitando', 'iaAviso', 'iaSugestoes', 'iaPlaceholder', 'iaAria'] as $chave) {
            self::assertStringContainsString('raiz.dataset.' . $chave, $js);
        }
        self::assertDoesNotMatchRegularExpression('/[\'"](Pergunte em linguagem natural|digitando\.\.\.)/', $js, 'texto da IA é do Twig');
        // o ramo da IA no menu da conversa sai antes de Fixar, Arquivar e Excluir
        self::assertMatchesRegularExpression('/if \(ehIa\(c\)\) \{\s*\/\/ F3[\s\S]*?abrirMenu\(itens, x, y, origem\);\s*return;\s*\}\s*itens\.push\(\{ icone: \'bi-pin-angle\'/', $js);
        // estado vazio: só sugestões que o servidor sabe responder (sem o resumo da pasta, PR 4) e sem innerHTML (SVG por createElementNS)
        self::assertStringContainsString('createElementNS', $js);
    }

    #[TestDox('Pergunta presa destrava o campo, 503 relê o histórico e a pergunta tem limite de 2000')]
    public function testIaNaoTravaOCampoNemDuplicaAPergunta(): void
    {
        $js = (string) file_get_contents(self::DIR . 'central.js');
        self::assertStringContainsString('ESPERA_MAXIMA_DA_IA', $js);
        self::assertStringContainsString('raiz.dataset.iaDemora', $js);
        self::assertMatchesRegularExpression('/e\.status === 503[\s\S]*?\/mensagens/', $js);
        self::assertStringContainsString('TAMANHO_MAXIMO_DA_PERGUNTA = 2000', $js);
    }
}
