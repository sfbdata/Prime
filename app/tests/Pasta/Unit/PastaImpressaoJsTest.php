<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * `public/js/pasta-impressao.js` lido como FOLHA (não há navegador no PHPUnit): o que se prova é
 * a parte de segurança que não aparece na tela — o iframe da impressão não executa script, a
 * folha do servidor e o texto da tela entram por DOM (nada de HTML montado em string com dado) —
 * e o contrato com o template da janela e com a rota da folha.
 */
#[Group('pasta')]
final class PastaImpressaoJsTest extends TestCase
{
    private const JS     = __DIR__ . '/../../../public/js/pasta-impressao.js';
    private const MODAL  = __DIR__ . '/../../../templates/pasta/_impressao_modal.html.twig';

    private function js(): string
    {
        $js = file_get_contents(self::JS);
        self::assertIsString($js);

        return $js;
    }

    #[TestDox('O iframe de impressão é sandbox SEM permissão de script, com CSP fechada')]
    public function testIframeSemScript(): void
    {
        $js = $this->js();

        self::assertStringNotContainsString('allow-scripts', $js);
        self::assertSame(1, preg_match("/var SANDBOX_IMPRESSAO = '([^']*)';/", $js, $m));
        self::assertSame('allow-same-origin allow-modals', $m[1]);
        self::assertStringContainsString("fr.setAttribute('sandbox', SANDBOX_IMPRESSAO)", $js);

        self::assertSame(1, preg_match('/var CSP_IMPRESSAO = "([^"]*)";/', $js, $csp));
        self::assertStringContainsString("default-src 'none'", $csp[1]);
        self::assertStringNotContainsString('script-src', $csp[1], 'sem script-src, vale o default: nenhum script');
    }

    #[TestDox('O documento do iframe é feito só de constantes; a folha entra por importNode')]
    public function testSrcdocSoComConstantes(): void
    {
        $js = $this->js();

        self::assertSame(1, preg_match('/fr\.srcdoc = (.*?);\n/s', $js, $m), 'uma atribuição de srcdoc');
        $expressao = (string) preg_replace("/'[^']*'/", '', $m[1]);
        preg_match_all('/[A-Za-z_][A-Za-z0-9_.]*/', $expressao, $ids);
        self::assertSame(
            ['CSP_IMPRESSAO', 'CSS_IMPRESSAO'],
            array_values(array_unique($ids[0])),
            'nenhuma variável com dado no HTML do iframe',
        );
        self::assertStringContainsString('d.body.appendChild(d.importNode(copia, true))', $js);
    }

    #[TestDox('Nenhum HTML montado em string com dado: sem innerHTML, outerHTML, insertAdjacentHTML, document.write ou eval')]
    public function testSemHtmlEmString(): void
    {
        $js = $this->js();

        self::assertSame(0, preg_match('/\.(innerHTML|outerHTML)\b/', $js));
        foreach (['insertAdjacentHTML', 'document.write', 'eval(', 'new Function'] as $proibido) {
            self::assertStringNotContainsString($proibido, $js);
        }
        // A folha do servidor: DOMParser (não executa script), os <script> removidos, importNode.
        self::assertStringContainsString("new DOMParser().parseFromString(texto, 'text/html')", $js);
        self::assertStringContainsString("doc.querySelectorAll('script')", $js);
        self::assertStringContainsString('document.importNode(folha, true)', $js);
        // Título, autor e aviso da tela: textContent.
        self::assertStringContainsString('titulo.textContent = cfg.titulo', $js);
        self::assertStringContainsString('autor.textContent = cfg.autor', $js);
    }

    #[TestDox('A prévia pede a folha ao servidor como fragmento, com todas as partes; as opções escondem por classe')]
    public function testContratoComAFolha(): void
    {
        $js = $this->js();

        self::assertStringContainsString("u.searchParams.set('fragmento', '1')", $js);
        self::assertStringContainsString("u.searchParams.set('secao', secao)", $js);
        self::assertStringContainsString("var PARTES = ['cabecalho', 'dados', 'autor', 'financeiro'];", $js);
        self::assertStringContainsString("classList.toggle('rp-sem-' + parte, !ligado)", $js);
        self::assertStringContainsString("credentials: 'same-origin'", $js);
        // Tamanho do texto: 9 a 16, padrão 11,5, passos de 0,5.
        self::assertStringContainsString('var FONTE_MIN = 9;', $js);
        self::assertStringContainsString('var FONTE_MAX = 16;', $js);
        self::assertStringContainsString('var FONTE_PADRAO = 11.5;', $js);
        self::assertStringContainsString('estado.fonte - 0.5', $js);
        self::assertStringContainsString('estado.fonte + 0.5', $js);
    }

    #[TestDox('Ids e classes que o JS usa existem no template da janela')]
    public function testContratoComOTemplate(): void
    {
        $js   = $this->js();
        $twig = file_get_contents(self::MODAL);
        self::assertIsString($twig);

        foreach (['psImpressao', 'psImpressaoPalco', 'psImpressaoTitulo', 'psImpressaoFonte'] as $id) {
            self::assertStringContainsString("'" . $id . "'", $js);
            self::assertStringContainsString('id="' . $id . '"', $twig);
        }
        foreach (['js-psi-fechar', 'js-psi-opcao', 'js-psi-menor', 'js-psi-maior', 'js-psi-imprimir', 'js-psi-copiar', 'data-url-folha'] as $marca) {
            self::assertStringContainsString($marca, $js);
            self::assertStringContainsString($marca, $twig);
        }
    }
}
