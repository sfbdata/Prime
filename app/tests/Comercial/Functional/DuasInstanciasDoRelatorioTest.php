<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Functional;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;
use Twig\Environment;

/**
 * O Relatório da Central de envios nas duas seções do /master (spec docs/specs/comercial-relatorio-venda.md; desenho 05
 * VS 21.10.26, `secCom` e `secCentral` L627-645): o mesmo parcial como filho direto da raiz de cada modo
 * (`.mc[data-modo]`), depois da Central, com a seção no `data-mc-relatorio`, os ids com sufixo (nenhum id repete na
 * página) e os recursos (<link>, <script>) uma vez só. As duas seções vêm na mesma página, como o painel monta
 * (painel.html.twig L59-60).
 */
#[CoversNothing]
final class DuasInstanciasDoRelatorioTest extends KernelTestCase
{
    private const COM = '[data-sec-externa="com"] > .mc[data-mc][data-modo="com"]';
    private const IND = '[data-sec-externa="ind"] > .mi[data-mi] > .mc.mc-venda[data-mc-venda][data-modo="venda"]';

    private Crawler $doc;

    protected function setUp(): void
    {
        self::bootKernel();
        static::getContainer()->set('security.csrf.token_storage', new class () implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }

            public function setToken(string $tokenId, string $token): void
            {
            }

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function hasToken(string $tokenId): bool
            {
                return true;
            }

            public function clear(): void
            {
            }
        });
        $html = static::getContainer()->get(Environment::class)->createTemplate(
            '<div class="mp-secao-externa" data-sec-externa="com" hidden>{% include "master/_comercial.html.twig" %}</div>'
            . '<div class="mp-secao-externa" data-sec-externa="ind" hidden>{% include "master/_indicacoes.html.twig" %}</div>',
        )->render();
        $this->doc = new Crawler('<!doctype html><html><body>' . $html . '</body></html>');
    }

    #[TestDox('O Relatório é filho direto da raiz de cada modo, uma vez em cada, com a seção no data-mc-relatorio')]
    public function testUmaInstanciaPorSecao(): void
    {
        self::assertCount(2, $this->doc->filter('section.mc-rl[data-mc-relatorio]'));
        self::assertCount(1, $this->doc->filter(self::COM . ' > section.mc-rl[data-mc-relatorio="com"]'));
        self::assertCount(1, $this->doc->filter(self::IND . ' > section.mc-rl[data-mc-relatorio="ind"]'));
    }

    #[TestDox('Na seção Indicações o Relatório vem logo depois da Central; na Comercial, depois das Automações e do <link> dele')]
    public function testPosicao(): void
    {
        self::assertCount(1, $this->doc->filter(self::IND . ' > section.mc-cp[data-mc-campanha] + section.mc-rl[data-mc-relatorio="ind"]'));
        self::assertCount(1, $this->doc->filter(self::COM . ' > section[aria-labelledby="mc-auto-titulo"] + link + section.mc-rl[data-mc-relatorio="com"]'));
    }

    #[TestDox('As duas instâncias têm as mesmas rotas e o mesmo arranjo (abas, barra, visualizador e menu)')]
    public function testMesmaLista(): void
    {
        foreach (['data-url-lista', 'data-url-campanha', 'data-url-cancelar', 'data-url-marcas', 'data-url-csv'] as $atributo) {
            self::assertSame(
                $this->doc->filter('section.mc-rl[data-mc-relatorio="com"]')->attr($atributo),
                $this->doc->filter('section.mc-rl[data-mc-relatorio="ind"]')->attr($atributo),
                $atributo,
            );
        }
        $estrutura = fn (string $secao): string => (string) preg_replace(
            ['/-' . $secao . '"/', '/data-mc-relatorio="' . $secao . '"/', '/data-token="[^"]*"/'],
            ['-X"', 'data-mc-relatorio="X"', 'data-token=""'],
            $this->doc->filter('section.mc-rl[data-mc-relatorio="' . $secao . '"]')->outerHtml(),
        );
        self::assertSame($estrutura('com'), $estrutura('ind'), 'fora a seção, o sufixo dos ids e o token, o HTML é o mesmo');
    }

    #[TestDox('Nenhum id repete na página, e os aria-labelledby do Relatório apontam para o id da própria instância')]
    public function testIdsUnicos(): void
    {
        $ids = $this->doc->filter('[id]')->each(static fn (Crawler $c): string => (string) $c->attr('id'));
        self::assertSame([], array_values(array_unique(array_diff_assoc($ids, array_unique($ids)))), 'ids repetidos');
        foreach (['com', 'ind'] as $secao) {
            $rl = $this->doc->filter('section.mc-rl[data-mc-relatorio="' . $secao . '"]');
            self::assertSame('mc-rl-ver-assunto-' . $secao, $rl->filter('.mc-rl-ver-caixa')->attr('aria-labelledby'));
            self::assertCount(1, $rl->filter('#mc-rl-ver-assunto-' . $secao));
            self::assertSame('mc-rl-ctx-titulo-' . $secao, $rl->filter('.mc-rl-ctx-caixa')->attr('aria-labelledby'));
            self::assertCount(1, $rl->filter('#mc-rl-ctx-titulo-' . $secao));
        }
    }

    #[TestDox('O <link> e o <script> do Relatório vêm uma vez só, com a instância da seção Comercial')]
    public function testRecursosUmaVez(): void
    {
        self::assertCount(1, $this->doc->filter('link[href*="css/master-comercial-relatorio.css"]'));
        self::assertCount(1, $this->doc->filter('script[src*="js/master/comercial-relatorio.js"]'));
        self::assertCount(1, $this->doc->filter('[data-sec-externa="com"] script[src*="js/master/comercial-relatorio.js"]'));
        self::assertCount(0, $this->doc->filter('[data-sec-externa="ind"] script[src*="js/master/comercial-relatorio.js"]'));
    }

    #[TestDox('O JS: um init por raiz, idempotente, que inicia de novo quando o documento termina; Duplicar na raiz e no compositor da mesma seção')]
    public function testJsMultiInstancia(): void
    {
        $js = (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/public/js/master/comercial-relatorio.js');
        foreach ([
            "document.querySelectorAll('[data-mc-relatorio]').forEach(iniciar)",
            'if (raiz.dataset.mcRlIniciado) return;',
            "document.addEventListener('DOMContentLoaded', iniciarTodas)",
            "raiz.dispatchEvent(new CustomEvent('mc:duplicar-campanha', { bubbles: true, cancelable: true, detail: detalhe }))",
            "raiz.closest('.mc[data-modo]')",
            'e.detail.sec === secao',
            'recarregarOutras(instancia)',
        ] as $trecho) {
            self::assertStringContainsString($trecho, $js);
        }
        self::assertStringNotContainsString("document.querySelector('[data-mc-relatorio]')", $js, 'nada de instância única');
        self::assertStringNotContainsString("document.dispatchEvent(new CustomEvent('mc:duplicar-campanha'", $js);
    }
}
