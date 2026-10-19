<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * `public/js/pasta-push-link.js` lido como folha (não há navegador no PHPUnit): o que se prova é a
 * segurança (dado do servidor nunca vira HTML), o contrato com o template e com a rota JSON, e os
 * textos do desenho que moram no JS.
 */
#[Group('pasta')]
final class PastaPushLinkJsTest extends TestCase
{
    private const JS = __DIR__ . '/../../../public/js/pasta-push-link.js';
    private const TWIG = __DIR__ . '/../../../templates/pasta/_push_link_externo.html.twig';
    private const CABECALHO = __DIR__ . '/../../../templates/pasta/_cabecalho.html.twig';

    private function js(): string
    {
        return (string) file_get_contents(self::JS);
    }

    /** O código sem os comentários: o que conta é o que roda. */
    private function codigo(): string
    {
        $semBloco = (string) preg_replace('#/\*.*?\*/#s', '', $this->js());

        return (string) preg_replace('#(^|[^:\'"])//[^\n]*#', '$1', $semBloco);
    }

    #[TestDox('Nunca monta HTML: sem innerHTML, outerHTML, insertAdjacentHTML nem document.write; o dado entra por textContent')]
    public function testSemHtmlMontado(): void
    {
        $codigo = $this->codigo();

        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'DOMParser', 'createContextualFragment'] as $proibido) {
            self::assertStringNotContainsString($proibido, $codigo, "{$proibido} no pasta-push-link.js");
        }
        self::assertStringContainsString('.textContent = ', $codigo);
        self::assertStringContainsString("document.createElement(tag)", $codigo);
    }

    #[TestDox('É uma IIFE em modo estrito, que não faz nada sem o modal na página')]
    public function testIife(): void
    {
        $js = trim((string) preg_replace('#^/\*.*?\*/#s', '', $this->js()));

        self::assertStringStartsWith('(function () {', $js);
        self::assertStringEndsWith('})();', $js);
        self::assertStringContainsString("'use strict';", $js);
        self::assertStringContainsString("document.getElementById('modalLinkExternoPush');\n    if (!raiz) { return; }", $js);
    }

    #[TestDox('Contrato com o servidor: URL e CSRF dos data-*, o campo _token e os três campos do gerar; ações por /<id>/revogar|renovar|recusar')]
    public function testContratoComAsRotas(): void
    {
        $codigo = $this->codigo();

        self::assertStringContainsString("raiz.getAttribute('data-url-links')", $codigo);
        self::assertStringContainsString("raiz.getAttribute('data-csrf')", $codigo);
        self::assertStringContainsString("corpo.append('_token', CSRF)", $codigo);
        foreach (['exigir_codigo:', 'mostrar_cliente:', 'incluir_analises:'] as $campo) {
            self::assertStringContainsString($campo, $codigo);
        }
        self::assertStringContainsString("URL_LINKS + '/' + encodeURIComponent(String(link.id)) + '/' + acao", $codigo);
        foreach (["'revogar'", "'renovar'", "'recusar'"] as $acao) {
            self::assertStringContainsString($acao, $codigo);
        }
        // Campos do JSON (LinkExternoDoPushOutput / LinksExternosDaPastaOutput) que a tela lê.
        foreach (['textoParaCopiar', 'podeGerar', 'motivoBloqueio', 'analisesCompartilhaveis', 'totalAtivos', 'totalAcessos', 'ultimoAcesso', 'podeRevogar', 'podeRenovar', 'podeRecusar', 'estadoRotulo', 'comCodigo', 'expiraEm', 'porDia', 'ultimos'] as $campo) {
            self::assertStringContainsString($campo, $codigo, "o JS lê {$campo}");
        }
    }

    #[TestDox('Contrato com o template: cada classe/id que o JS procura existe no parcial ou no cabeçalho')]
    public function testContratoComOTemplate(): void
    {
        $codigo = $this->codigo();
        $twig = (string) file_get_contents(self::TWIG) . (string) file_get_contents(self::CABECALHO);

        preg_match_all("/'\\.(js-ppl-[a-z-]+)/", $codigo, $classes);
        $classes = array_unique($classes[1]);
        self::assertNotEmpty($classes);
        // Classes criadas pelo próprio JS nas linhas da lista não precisam estar no template.
        $doJs = ['js-ppl-linha-ac', 'js-ppl-linha-copiar', 'js-ppl-linha-revogar', 'js-ppl-linha-renovar', 'js-ppl-linha-recusar'];
        foreach ($classes as $classe) {
            if (in_array($classe, $doJs, true)) {
                self::assertStringContainsString("'ppl-", $codigo);
                continue;
            }
            self::assertStringContainsString($classe, $twig, "{$classe} é contrato com o template");
        }
        foreach (["getElementById('pushLinkMenu')", "getElementById('pushLinkAcessos')", "closest('[data-ppl-menu]')", "'[data-ps-pop=\"psMenuAcoes\"]'"] as $trecho) {
            self::assertStringContainsString($trecho, $codigo);
        }
        self::assertStringContainsString('id="pushLinkMenu"', $twig);
        self::assertStringContainsString('id="pushLinkAcessos"', $twig);
        self::assertStringContainsString('data-ppl-menu="pushLinkMenu"', $twig);
    }

    #[TestDox('Revogar pergunta pela janela da tela (confirmarPs) com o texto exato do desenho; avisos pelo avisarPs')]
    public function testRevogarPerguntaComOTextoDoDesenho(): void
    {
        $codigo = $this->codigo();

        self::assertStringContainsString('window.confirmarPs({', $codigo);
        self::assertStringContainsString("texto: 'Revogar este link? Quem tiver o endereço perde o acesso na hora.'", $codigo);
        self::assertStringContainsString('window.avisarPs(texto, tipo)', $codigo);
        self::assertStringContainsString("'Link renovado por 30 dias'", $codigo);
        self::assertStringContainsString("'Link revogado'", $codigo);
    }

    #[TestDox('Menu da aba: botão direito, Shift+F10 e a tecla de menu abrem; Esc fecha; textos dos itens do desenho')]
    public function testMenuDaAba(): void
    {
        $codigo = $this->codigo();

        self::assertStringContainsString("document.addEventListener('contextmenu'", $codigo);
        self::assertStringContainsString("e.key === 'ContextMenu' || (e.shiftKey && e.key === 'F10')", $codigo);
        self::assertStringContainsString("e.key === 'Escape'", $codigo);
        self::assertStringContainsString("'Gerar novo link externo' : 'Gerar link externo do Push'", $codigo);
        self::assertStringContainsString("'Ver acessos ao link · '", $codigo);
        self::assertStringContainsString("'Link copiado · válido até ' + dataSo(ativo.expiraEm) + ' · ' + (ativo.comCodigo ? 'com' : 'sem') + ' código de acesso. Análises com cadeado fechado não aparecem.'", $codigo);
    }

    #[TestDox('Copiar usa a área de transferência do navegador e, sem ela, o textarea')]
    public function testCopiarComFallback(): void
    {
        $codigo = $this->codigo();

        self::assertStringContainsString('navigator.clipboard.writeText(texto)', $codigo);
        self::assertStringContainsString("document.createElement('textarea')", $codigo);
        self::assertStringContainsString("document.execCommand('copy')", $codigo);
        self::assertStringContainsString("window.open(estado.criado.url, '_blank', 'noopener')", $codigo);
    }

    #[TestDox('Sem travessão em nenhum texto do JS, do CSS e do parcial (regra do dono)')]
    public function testSemTravessao(): void
    {
        foreach ([self::JS, self::TWIG, __DIR__ . '/../../../public/css/pasta-push-link.css'] as $arquivo) {
            self::assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', (string) file_get_contents($arquivo), basename($arquivo));
        }
    }
}
