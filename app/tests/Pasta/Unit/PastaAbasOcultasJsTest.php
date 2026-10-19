<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Unit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * `public/js/pasta-abas-ocultas.js` lido como folha (o container de teste não tem motor de JS): a
 * segurança (só textContent) e o contrato de ids, classes e `data-*` com a marcação de verdade
 * (`_abas_ocultas.html.twig`, `_cabecalho.html.twig`) e com o ponto de extensão do pasta-push-link.js.
 */
#[Group('pasta')]
final class PastaAbasOcultasJsTest extends TestCase
{
    private const JS = __DIR__ . '/../../../public/js/pasta-abas-ocultas.js';
    private const PUSH_JS = __DIR__ . '/../../../public/js/pasta-push-link.js';
    private const CSS = __DIR__ . '/../../../public/css/pasta-abas-ocultas.css';
    private const TWIG = __DIR__ . '/../../../templates/pasta/_abas_ocultas.html.twig';
    private const CABECALHO = __DIR__ . '/../../../templates/pasta/_cabecalho.html.twig';
    private const SHOW = __DIR__ . '/../../../templates/pasta/show.html.twig';

    private function js(): string
    {
        return (string) file_get_contents(self::JS);
    }

    #[TestDox('Nada de HTML montado em texto: sem innerHTML, outerHTML, insertAdjacentHTML nem document.write')]
    public function testSemInnerHtml(): void
    {
        foreach (['innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write'] as $proibido) {
            self::assertStringNotContainsString($proibido, $this->js(), $proibido);
        }
        self::assertStringContainsString('.textContent = p.nome', $this->js());
    }

    #[TestDox('Marca do modal troca só o ícone (classList): reescrever className apagava a classe js- de busca')]
    public function testNaoReescreveClassName(): void
    {
        self::assertDoesNotMatchRegularExpression('/\.className\s*=/', $this->js());
        self::assertStringContainsString("marca.classList.remove('bi-check-square-fill', 'bi-dash-square-fill', 'bi-square')", $this->js());
    }

    #[TestDox('Todo id que o JS procura existe na marcação')]
    public function testIdsExistem(): void
    {
        preg_match_all("/getElementById\\('([A-Za-z]+)'\\)|q\\('#([A-Za-z]+)'/", $this->js(), $m);
        $ids = array_unique(array_filter(array_merge($m[1], $m[2])));
        self::assertContains('paoRaiz', $ids);
        $marcacao = file_get_contents(self::TWIG) . file_get_contents(dirname(self::TWIG) . '/_push_link_externo.html.twig');
        foreach ($ids as $id) {
            self::assertStringContainsString('id="' . $id . '"', $marcacao, $id);
        }
    }

    #[TestDox('Toda classe js-pao-* e pao-* que o JS usa existe no template, e toda pao-* do template tem estilo')]
    public function testClassesExistem(): void
    {
        preg_match_all('/[\'". ](js-pao-[a-z-]+|pao-[a-z-]+)/', $this->js(), $m);
        $twig = (string) file_get_contents(self::TWIG);
        $css = (string) file_get_contents(self::CSS);
        foreach (array_unique($m[1]) as $classe) {
            if (in_array($classe, ['pao-aviso'], true)) {
                continue; // a chave do sessionStorage, não uma classe
            }
            self::assertMatchesRegularExpression('/class="[^"]*\b' . preg_quote($classe, '/') . '\b/', $twig, $classe);
        }
        preg_match_all('/class="([^"]+)"/', $twig, $c);
        foreach (array_unique(preg_split('/\s+/', implode(' ', $c[1])) ?: []) as $classe) {
            if (str_starts_with($classe, 'pao-')) {
                self::assertStringContainsString('.' . $classe, $css, $classe);
            }
        }
    }

    #[TestDox('As abas levam o que o JS lê (data-pao-aba, -rotulo, -regra, -sufixo) e a Push segue com data-ppl-menu')]
    public function testDadosDaAba(): void
    {
        $cabecalho = (string) file_get_contents(self::CABECALHO);
        foreach (['data-pao-aba', 'data-pao-rotulo', 'data-pao-regra', 'data-pao-sufixo', 'data-ppl-menu'] as $atributo) {
            self::assertStringContainsString($atributo . '="', $cabecalho, $atributo);
            self::assertStringContainsString($atributo, $this->js(), $atributo);
        }
    }

    #[TestDox('Menu da aba Push: os itens entram com data-ppl-externo, e o pasta-push-link.js fecha o menu neles sem agir')]
    public function testPontoDeExtensaoDoPush(): void
    {
        self::assertStringContainsString("setAttribute('data-ppl-externo', '')", $this->js());
        self::assertStringContainsString("menuPush.insertBefore(copia, depois)", $this->js());
        self::assertMatchesRegularExpression("/closest\\('\\[data-ppl-externo\\]'\\)\\) \\{ fecharMenu\\(false\\); return; \\}/", (string) file_get_contents(self::PUSH_JS));
    }

    #[TestDox('Sem configurar: o aviso do desenho; com configurar e na aba Push, nada (o menu é do link externo)')]
    public function testQuemNaoConfigura(): void
    {
        self::assertStringContainsString("'Só o usuário primário pode ocultar abas'", $this->js());
        self::assertStringContainsString('avisar(AVISO_SO_PRIMARIO);', $this->js(), 'estilo neutro do gmAviso (L.4411), não o de erro');
        self::assertStringContainsString("!aba.hasAttribute('data-ppl-menu')", $this->js());
    }

    #[TestDox('Carga: depois do pasta-dialogos.js (avisarPs) e do pasta-push-link.js (#pushLinkMenu)')]
    public function testOrdemDosScripts(): void
    {
        $show = (string) file_get_contents(self::SHOW);
        $dialogos = strpos($show, "js/pasta-dialogos.js");
        $push = strpos($show, "js/pasta-push-link.js");
        $abas = strpos($show, "js/pasta-abas-ocultas.js");
        self::assertNotFalse($abas);
        self::assertGreaterThan($dialogos, $abas);
        self::assertGreaterThan($push, $abas);
        self::assertStringContainsString("css/pasta-abas-ocultas.css", $show);
    }

    #[TestDox('Texto visível sem travessão')]
    public function testSemTravessao(): void
    {
        foreach ([self::JS, self::TWIG, self::CSS] as $arquivo) {
            self::assertDoesNotMatchRegularExpression('/[\x{2014}\x{2013}]/u', (string) file_get_contents($arquivo), basename($arquivo));
        }
    }
}
