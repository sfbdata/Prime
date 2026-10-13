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

    #[TestDox('EDT-002/C6-EDT-06: Baixar PDF leva as mesmas opções à rota de PDF; na moldura, só com o alvo (id) que o servidor monta')]
    public function testBaixarPdf(): void
    {
        $js     = $this->js();
        $inicio = strpos($js, 'function baixarPdf()');
        $fim    = strpos($js, 'function copiarTexto()');
        self::assertNotFalse($inicio);
        self::assertNotFalse($fim);
        $f = substr($js, $inicio, $fim - $inicio);

        self::assertStringContainsString("var alvo = estado.secao === 'moldura' ? estado.pdf : { secao: estado.secao };", $f);
        self::assertStringContainsString('if (!alvo) { return; }', $f, 'moldura sem alvo do servidor não baixa nada');
        self::assertStringContainsString("raiz.getAttribute('data-url-pdf')", $f);
        self::assertStringContainsString('u.searchParams.set(chave, String(alvo[chave]))', $f);
        self::assertStringContainsString("u.searchParams.set(parte, estado.opcoes[parte] ? '1' : '0')", $f);
        self::assertStringContainsString("u.searchParams.set('fonte', String(estado.fonte))", $f);
        self::assertStringContainsString("raiz.querySelector('.js-psi-baixar').hidden = cfg.secao === 'moldura' && !cfg.pdf;", $js);
        self::assertStringContainsString('pdf: cfg.pdf || null,', $js);
        // O PDF nunca leva o corpo da tela: nem a folha nem o nó do corpo entram no pedido.
        self::assertStringNotContainsString('estado.folha', $f);
        self::assertStringNotContainsString('cfg.corpo', $f);
    }

    #[TestDox('C6-EDT-06: o registro pede o PDF pelo id do cartão (secao=observacao&obs=…) e o processo pelo data-processo-id do cartão (secao=processo&processo=…)')]
    public function testAlvoDoPdfDoRegistroEDoProcesso(): void
    {
        $js = $this->js();

        self::assertStringContainsString('/^detalhes-obs-(\d+)$/.exec(artigo.id || \'\')', $js);
        self::assertStringContainsString("{ secao: 'observacao', obs: id[1] }", $js);

        $inicio = strpos($js, 'var processoDoClique = null;');
        self::assertNotFalse($inicio);
        $bloco = substr($js, $inicio, 500);
        self::assertStringContainsString("e.target.closest('.js-proc-imprimir')", $bloco);
        self::assertStringContainsString("item.closest('[data-processo-id]')", $bloco);
        self::assertStringContainsString('}, true);', $bloco, 'em captura: o id é guardado ANTES de o pasta-processo.js chamar conteudo()');
        self::assertStringContainsString("{ secao: 'processo', processo: processoDoClique }", $js);
    }

    #[TestDox('C6-EDT-07: o iframe imprime com 18 mm em cima e embaixo de toda página e 16 mm nos lados; a 1ª página sem margem de cima, para a faixa azul')]
    public function testMargemDeTodaPaginaNaImpressao(): void
    {
        $js = $this->js();
        self::assertSame(1, preg_match('/var CSS_IMPRESSAO = (.*?);\n/s', $js, $m));
        // Junta os pedaços concatenados ('a' + 'b') numa string só.
        $css = (string) preg_replace("/'\s*\+\s*'/", '', $m[1]);

        self::assertStringContainsString('@page{size:A4;margin:18mm 0}', $css);
        self::assertStringContainsString('@page :first{margin-top:0}', $css);
        self::assertStringNotContainsString('@page{size:A4;margin:0}', $css, 'nada de @page com margem zero em toda página');
        self::assertStringContainsString('body .rp-folha{width:auto;min-height:0;margin:0;box-shadow:none;padding:18mm 16mm 0}', $css);
        self::assertStringContainsString('body .rp-faixa{margin:-18mm -16mm 30px}', $css);
    }

    #[TestDox('EDT-003: "Imprimir" do registro abre a moldura com "Registro do expediente", a linha autor · data · tipo e o texto CLONADO do cartão')]
    public function testImprimirDoRegistro(): void
    {
        $js     = $this->js();
        $inicio = strpos($js, 'function imprimirRegistro(item, artigo) {');
        self::assertNotFalse($inicio);
        $bloco = substr($js, $inicio, 1300);

        self::assertStringContainsString("artigo.querySelector('.ps-anotacao-texto .ql-editor')", $bloco);
        self::assertStringContainsString('n.cloneNode(true)', $bloco, 'o HTML já sanitizado pelo servidor, copiado nó a nó');
        self::assertStringContainsString("[item.getAttribute('data-autor'), item.getAttribute('data-quando'), item.getAttribute('data-tipo')]", $bloco);
        self::assertStringContainsString(".join(' · ')", $bloco);
        self::assertStringContainsString("abrir({ secao: 'moldura', titulo: 'Registro do expediente', autor: linha, corpo: corpo, pdf: pdf })", $bloco);
    }

    #[TestDox('EDT-003: na observação de Detalhes o cartão é o ancestral do item; a API registro(item, cartao) segue para um menu único da lista')]
    public function testOsDoisCaminhosDoImprimirDoRegistro(): void
    {
        $js = $this->js();

        $inicio = strpos($js, "e.target.closest('.js-obs-imprimir')");
        self::assertNotFalse($inicio);
        self::assertStringContainsString("imprimirRegistro(item, item.closest('.ps-anotacao'))", substr($js, $inicio, 200));
        self::assertStringContainsString('registro: function (item, artigo) { return imprimirRegistro(item, artigo); }', $js);
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
        foreach (['js-psi-fechar', 'js-psi-opcao', 'js-psi-menor', 'js-psi-maior', 'js-psi-imprimir', 'js-psi-baixar', 'js-psi-copiar', 'data-url-folha', 'data-url-pdf'] as $marca) {
            self::assertStringContainsString($marca, $js);
            self::assertStringContainsString($marca, $twig);
        }
    }
}
