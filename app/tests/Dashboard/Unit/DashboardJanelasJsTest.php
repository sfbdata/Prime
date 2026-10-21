<?php

declare(strict_types=1);

namespace App\Tests\Dashboard\Unit;

use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * DASH-020: o componente de janelas flutuantes (`public/js/dashboard-janelas.js`) lido como
 * FOLHA — não há navegador no PHPUnit. O que se prova é o contrato: diálogo acessível, Esc na
 * janela da frente, foco que entra e volta, X, arraste com os limites do desenho e nenhum
 * innerHTML. Arrastar e empilhar de verdade é smoke do dono.
 */
final class DashboardJanelasJsTest extends TestCase
{
    private const JS  = __DIR__ . '/../../../public/js/dashboard-janelas.js';
    private const CSS = __DIR__ . '/../../../public/css/dashboard-janelas.css';
    private const TWIG = __DIR__ . '/../../../templates/dashboard/index.html.twig';
    private const CATALOGO = __DIR__ . '/../../../translations/messages+intl-icu.pt_BR.yaml';

    private function js(): string
    {
        return (string) file_get_contents(self::JS);
    }

    /** Corpo de uma função nomeada, até a próxima `function ` no mesmo nível (aproximação suficiente). */
    private function funcao(string $nome): string
    {
        $js     = $this->js();
        $inicio = strpos($js, 'function ' . $nome . '(');
        self::assertNotFalse($inicio, 'função ' . $nome . ' não encontrada');
        $fim = strpos($js, "\n    function ", $inicio + 10);

        return substr($js, $inicio, $fim === false ? null : $fim - $inicio);
    }

    #[TestDox('nada de innerHTML/outerHTML/insertAdjacentHTML/document.write: dado entra só por textContent')]
    public function testSemInnerHtml(): void
    {
        $js = $this->js();
        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write'] as $proibido) {
            self::assertStringNotContainsString($proibido, $js, $proibido . ' no componente de janelas');
        }
        self::assertStringContainsString('n.textContent = String(texto);', $js);
    }

    #[TestDox('cada janela é role=dialog não modal, rotulada pelo título e focável')]
    public function testDialogoAcessivel(): void
    {
        $abrir = $this->funcao('abrir');

        self::assertStringContainsString("caixa.setAttribute('role', 'dialog');", $abrir);
        self::assertStringContainsString("caixa.setAttribute('aria-modal', 'false');", $abrir);
        self::assertStringContainsString("caixa.setAttribute('aria-labelledby', id + '-titulo');", $abrir);
        self::assertStringContainsString("titulo.id = id + '-titulo';", $abrir);
        self::assertStringContainsString("caixa.setAttribute('tabindex', '-1');", $abrir);
    }

    #[TestDox('o foco entra na janela ao abrir e volta para quem abriu ao fechar')]
    public function testFocoEntraEVolta(): void
    {
        $abrir = $this->funcao('abrir');
        self::assertStringContainsString('voltarPara: o.origem || document.activeElement', $abrir);
        self::assertStringContainsString('caixa.focus({ preventScroll: true });', $abrir);

        $fechar = $this->funcao('fechar');
        self::assertStringContainsString('var tinhaFoco = j.elemento.contains(document.activeElement);', $fechar);
        self::assertStringContainsString('j.voltarPara.focus({ preventScroll: true });', $fechar);
        self::assertStringContainsString('j.elemento.remove();', $fechar);
    }

    #[TestDox('Esc fecha a janela da FRENTE (a última da pilha), sem atropelar o Esc de campo ou lista aberta fora dela')]
    public function testEscFechaADaFrente(): void
    {
        $js = $this->js();
        self::assertMatchesRegularExpression(
            "/document\\.addEventListener\\('keydown', function \\(e\\) \\{\\s*if \\(e\\.key !== 'Escape' \\|\\| abertas\\.length === 0 \\|\\| escDeOutroComponente\\(e\\)\\) \\{ return; \\}\\s*e\\.preventDefault\\(\\);\\s*fechar\\(abertas\\[abertas\\.length - 1\\]\\);/",
            $js,
        );
        $outro = $this->funcao('escDeOutroComponente');
        self::assertStringContainsString('e.defaultPrevented', $outro);
        self::assertStringContainsString('[aria-expanded="true"]', $outro);
    }

    #[TestDox('X de fechar: botão com aria-label "Fechar" que fecha a janela')]
    public function testBotaoFechar(): void
    {
        $abrir = $this->funcao('abrir');
        self::assertStringContainsString("x.type = 'button';", $abrir);
        // O texto mora no catálogo do idioma (spec idioma-do-sistema): a chave no código, "Fechar" no catálogo.
        self::assertStringContainsString("x.setAttribute('aria-label', window.BJ_T.t('dashboard.js.janelas.fechar'));", $abrir);
        self::assertSame('Fechar', Yaml::parseFile(self::CATALOGO)['dashboard']['js']['janelas']['fechar']);
        self::assertStringContainsString("x.addEventListener('click', function () { fechar(j); });", $abrir);
    }

    #[TestDox('várias janelas: mesma chave só vem para a frente; nova abre 18px deslocada; clique traz para a frente')]
    public function testVariasJanelas(): void
    {
        $js    = $this->js();
        $abrir = $this->funcao('abrir');

        self::assertStringContainsString('var DESLOCA   = 18;', $js);
        self::assertMatchesRegularExpression('/var ja = porChave\(chave\);\s*if \(ja\) \{\s*frente\(ja\);/', $abrir);
        self::assertStringContainsString("caixa.addEventListener('pointerdown', function () { frente(j); });", $abrir);
        self::assertStringContainsString('n * DESLOCA', $this->funcao('posicaoInicial'));
    }

    #[TestDox('arraste pelo cabeçalho, a partir da posição visível, com os limites 56px (topo) e 96px (lados)')]
    public function testArrasteComLimites(): void
    {
        $js = $this->js();
        self::assertStringContainsString('var ALCA_VIS  = 56;', $js);
        self::assertStringContainsString('var LADO_VIS  = 96;', $js);

        $arraste = $this->funcao('ligarArraste');
        self::assertStringContainsString("alca.addEventListener('pointerdown'", $arraste);
        self::assertStringContainsString('var rc = j.elemento.getBoundingClientRect();', $arraste);
        self::assertStringContainsString("e.target.closest('button, a, input, select, textarea')", $arraste, 'o X não inicia arraste');

        $limitar = $this->funcao('limitar');
        self::assertStringContainsString('Math.max(-largura + LADO_VIS, Math.min(vw - LADO_VIS, x))', $limitar);
        self::assertStringContainsString('Math.max(TOPO_MIN, Math.min(vh - ALCA_VIS, y))', $limitar);
    }

    #[TestDox('a casca do Dashboard carrega o componente (CSS e JS) e o CSS esconde as janelas na impressão e tem o par escuro')]
    public function testCarregadoNaTelaEImpressao(): void
    {
        $twig = (string) file_get_contents(self::TWIG);
        self::assertStringContainsString("asset('js/dashboard-janelas.js')", $twig);
        self::assertStringContainsString("asset('css/dashboard-janelas.css')", $twig);
        self::assertLessThan(strpos($twig, "asset('js/dashboard.js')"), strpos($twig, "asset('js/dashboard-janelas.js')"));

        $css = (string) file_get_contents(self::CSS);
        self::assertMatchesRegularExpression('/@media print \{\s*\.db-janela \{ display: none !important; \}/', $css);
        self::assertStringContainsString('[data-bs-theme="dark"] .db-janela {', $css);
    }
}
