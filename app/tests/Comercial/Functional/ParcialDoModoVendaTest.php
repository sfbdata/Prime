<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Functional;

use App\Tests\Support\CatalogoPtBr;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;
use Twig\Environment;

/**
 * A Central de envios em modo venda na seção Indicações (F3h, spec comercial-e-emails §10; dc 05 VS 21.10.26, L244-296,
 * L627-645 e L846), com combinador de filho direto: a Central como filho direto do bloco das Indicações, com
 * `data-modo="venda"`, sem o seletor de modelo, os passos 1 PARA QUEM e 2 MENSAGEM, os 3 públicos em 3 colunas, o
 * "Cadastrar e-mail" no cabeçalho do PARA QUEM e o filtro só com a busca e o Estado. As duas seções vêm na mesma página,
 * como o painel monta (painel.html.twig L59-60): nenhum id, token ou raiz é compartilhado.
 */
#[CoversNothing]
final class ParcialDoModoVendaTest extends KernelTestCase
{
    private const CENTRAL = '[data-sec-externa="ind"] > .mi[data-mi] > .mc.mc-venda[data-mc-venda][data-modo="venda"]';
    private const EDITOR = self::CENTRAL . ' > section.mc-cp[data-mc-campanha] > .mc-cp-grade > .mc-cp-editor';

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
        $twig = static::getContainer()->get(Environment::class);
        // O mesmo arranjo das duas seções externas do painel.html.twig (L59-60).
        $html = $twig->createTemplate(
            '<div class="mp-secao-externa" data-sec-externa="com" hidden>{% include "master/_comercial.html.twig" %}</div>'
            . '<div class="mp-secao-externa" data-sec-externa="ind" hidden>{% include "master/_indicacoes.html.twig" %}</div>',
        )->render();
        $this->doc = new Crawler('<!doctype html><html><body>' . $html . '</body></html>');
    }

    #[TestDox('A Central é filho direto do bloco das Indicações, depois do resumo e dos limites, com data-modo="venda" e o próprio token')]
    public function testCentralNaSecaoIndicacoes(): void
    {
        self::assertCount(1, $this->doc->filter(self::CENTRAL));
        self::assertCount(1, $this->doc->filter('[data-sec-externa="ind"] > .mi > section.mi-caixa + link + .mc[data-modo="venda"]'), 'logo abaixo do resumo e dos limites');
        // O catálogo comercial.js e o bj-traducao.js vêm só com a seção Comercial, antes no HTML (spec idioma-do-sistema).
        self::assertCount(0, $this->doc->filter('[data-sec-externa="ind"] script[data-bj-t], [data-sec-externa="ind"] script[src*="js/bj-traducao.js"]'));
        self::assertCount(1, $this->doc->filter('[data-sec-externa="com"] script[src*="js/bj-traducao.js"]'));
        self::assertNotSame('', (string) $this->doc->filter(self::CENTRAL)->attr('data-token'));
        self::assertSame(['Central de envios · nova campanha'], $this->textos(self::CENTRAL . ' > section.mc-cp > .mc-cp-cabeca > .mc-cabeca-titulo'));
        // O que é só da seção Comercial (dc `secCom`): KPIs e Automações. Os scripts únicos dela também ficam de fora.
        // O Relatório aparece nas duas (spec comercial-relatorio-venda.md; ver DuasInstanciasDoRelatorioTest).
        self::assertCount(0, $this->doc->filter(self::CENTRAL . ' [data-mc-kpis], ' . self::CENTRAL . ' [data-mc-autos], ' . self::CENTRAL . ' [data-mc-dsc]'));
        self::assertCount(0, $this->doc->filter('[data-sec-externa="ind"] script[src*="js/master/comercial.js"]'));
        self::assertCount(1, $this->doc->filter('[data-sec-externa="ind"] script[src*="js/master/comercial-campanha.js"]'));
    }

    #[TestDox('Sem o seletor de modelo (cmMostraModelo), com os passos 1 · PARA QUEM e 2 · MENSAGEM (cmNPara/cmNMsg)')]
    public function testPassosSemModelo(): void
    {
        self::assertCount(0, $this->doc->filter(self::EDITOR . ' > [data-mc-cp-modelos], ' . self::EDITOR . ' > .mc-cp-acoes'));
        self::assertStringNotContainsString('MODELO', implode(' ', $this->textos(self::EDITOR . ' > .mc-cp-passo')));
        self::assertSame(['1 · PARA QUEM'], $this->textos(self::EDITOR . ' > .mc-cp-para-cabeca > .mc-cp-passo'));
        self::assertSame(['2 · MENSAGEM'], $this->textos(self::EDITOR . ' > .mc-cp-passo'));
        $ordem = $this->doc->filter(self::EDITOR . ' > *')->each(static fn (Crawler $c): string => (string) $c->attr('class'));
        self::assertSame(['mc-cp-para-cabeca', 'mc-cp-publicos mc-cp-publicos-3', 'mc-cf', 'mc-cp-csv', 'mc-cp-individual', 'mc-cp-passo mc-cp-passo-seguinte'], \array_slice($ordem, 0, 6));
    }

    #[TestDox('"Cadastrar e-mail" no cabeçalho do PARA QUEM (cmLeadAdd), com a URL própria e o aviso de LGPD do dc')]
    public function testCadastrarEmail(): void
    {
        $botao = self::EDITOR . ' > .mc-cp-para-cabeca > button.mc-cp-lead-add[data-mc-cp-lead-add]';
        self::assertSame(['Cadastrar e-mail'], $this->textos($botao));
        self::assertCount(1, $this->doc->filter($botao . ' > i.bi-person-plus'));
        self::assertSame('Só cadastre quem autorizou receber (LGPD)', $this->doc->filter($botao)->attr('title'));
        self::assertSame('/master/comercial/contatos', $this->doc->filter(self::CENTRAL . ' > section.mc-cp')->attr('data-url-contatos'));
        self::assertCount(0, $this->doc->filter('[data-sec-externa="com"] [data-mc-cp-lead-add], [data-sec-externa="com"] [data-url-contatos]'), 'só no modo venda');
        $css = $this->css();
        self::assertStringContainsString('.mc-cp-lead-add { height: 26px; padding: 0 10px; border: 1px dashed var(--mc-cp-novo-borda); border-radius: 13px; background: var(--mc-cp-novo-bg); color: var(--mc-azul);', $css);
        $js = (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/public/js/master/comercial-campanha.js');
        self::assertStringContainsString("window.prompt(BJ_T.t('comercial.js.contatos_pergunta'), '')", $js);
        $catalogo = CatalogoPtBr::carregar();
        self::assertSame('E-mail(s) de novos contatos (separe por vírgula). Só cadastre quem autorizou receber (LGPD):', $catalogo['comercial']['js']['contatos_pergunta'], 'o texto do prompt do dc (L644), no catálogo');
    }

    #[TestDox('Os 3 públicos da venda (Novos contatos, CSV e Individual) em 3 colunas, com os rótulos do dc (L640)')]
    public function testTresPublicos(): void
    {
        $botoes = self::EDITOR . ' > .mc-cp-publicos.mc-cp-publicos-3 > button.mc-cp-publico';
        self::assertSame(['leads', 'csv', 'indiv'], $this->doc->filter($botoes)->each(static fn (Crawler $c): string => (string) $c->attr('data-mc-cp-publico')));
        self::assertSame(['Novos contatos (base)', 'Importar lista (CSV)', 'Individual'], $this->textos($botoes . ' > .mc-cp-publico-texto > .mc-cp-publico-rot'));
        self::assertSame(['Interessados ainda sem conta', 'Planilha com e-mail e nome', 'Digite um ou mais e-mails'], $this->textos($botoes . ' > .mc-cp-publico-texto > .mc-cp-publico-desc'));
        self::assertSame('true', $this->doc->filter($botoes)->eq(0)->attr('aria-pressed'));
        self::assertStringContainsString('.mc-cp-publicos.mc-cp-publicos-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); }', $this->css());
    }

    #[TestDox('O filtro da venda só com a busca e o Estado: sem os atalhos e sem os outros selects (cmNaoVenda)')]
    public function testFiltroDaVenda(): void
    {
        $painel = self::EDITOR . ' > .mc-cf > .mc-cf-painel';
        self::assertCount(1, $this->doc->filter($painel . ' > .mc-cf-busca > input[data-mc-cf-campo="q"]'));
        self::assertCount(0, $this->doc->filter($painel . ' > .mc-cf-atalhos'));
        self::assertSame(['uf'], $this->doc->filter($painel . ' > .mc-cf-selects > select')->each(static fn (Crawler $c): string => (string) $c->attr('data-mc-cf-campo')));
        self::assertCount(28, $this->doc->filter($painel . ' > .mc-cf-selects > select[data-mc-cf-campo="uf"] > option'));
    }

    #[TestDox('As duas Centrais na mesma página: ids sem repetição, cada aria-controls aponta para o painel da própria Central, raízes e tokens próprios')]
    public function testDoisModosNaoSeConfundem(): void
    {
        $ids = $this->doc->filter('[id]')->each(static fn (Crawler $c): string => (string) $c->attr('id'));
        self::assertSame($ids, array_values(array_unique($ids)), 'nenhum id repetido na página');
        foreach (['com' => '[data-sec-externa="com"] > .mc[data-mc][data-modo="com"]', 'venda' => self::CENTRAL] as $modo => $raiz) {
            self::assertCount(1, $this->doc->filter($raiz), $modo);
            self::assertNotSame('', (string) $this->doc->filter($raiz)->attr('data-token'), $modo . ': o token na própria raiz');
            self::assertCount(1, $this->doc->filter($raiz . ' > section.mc-cp[data-mc-campanha][data-url-contagem][data-url-campanha]'), $modo);
            $this->doc->filter($raiz . ' [aria-controls]')->each(function (Crawler $c) use ($raiz, $modo): void {
                self::assertCount(1, $this->doc->filter($raiz . ' #' . $c->attr('aria-controls')), $modo . ': ' . $c->attr('aria-controls'));
            });
        }
        self::assertCount(1, $this->doc->filter('[data-mc]'), 'a raiz da venda não é [data-mc] (dos scripts únicos da seção Comercial)');
        self::assertCount(2, $this->doc->filter('[data-mc-campanha]'));
    }

    #[TestDox('A seção Comercial continua igual: modo com, o seletor de modelo, 2 · PARA QUEM, 6 públicos, atalhos e 8 selects')]
    public function testSecaoComercialIgual(): void
    {
        $editor = '[data-sec-externa="com"] > .mc[data-modo="com"] > section.mc-cp > .mc-cp-grade > .mc-cp-editor';
        self::assertSame(['1 · MODELO', '2 · PARA QUEM', '3 · MENSAGEM'], $this->textos($editor . ' > .mc-cp-passo'));
        self::assertCount(6, $this->doc->filter($editor . ' > .mc-cp-publicos:not(.mc-cp-publicos-3) > button'));
        self::assertCount(7, $this->doc->filter($editor . ' > .mc-cf > .mc-cf-painel > .mc-cf-atalhos > button'));
        self::assertCount(8, $this->doc->filter($editor . ' > .mc-cf > .mc-cf-painel > .mc-cf-selects > select'));
        self::assertCount(1, $this->doc->filter('[data-sec-externa="com"] > .mc > section.mc-cp > .mc-cp-cabeca > button[data-mc-cp-descad]'));
    }

    #[TestDox('O JS roda por raiz, com a tabela MODOS, e só a instância com responde pelos ganchos globais (F3f, F3g)')]
    public function testJsPorInstancia(): void
    {
        $js = (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/public/js/master/comercial-campanha.js');
        self::assertStringContainsString("document.querySelectorAll('[data-mc-campanha]').forEach(iniciar);", $js);
        self::assertStringNotContainsString("document.querySelector('[data-mc]')", $js);
        self::assertStringNotContainsString("document.querySelector('[data-mc-campanha]')", $js);
        self::assertSame(1, preg_match("/venda: \\{\\s*secao: 'ind', modelos: \\['convite', 'vagas', 'news', 'novidades'\\], publicos: \\['leads', 'csv', 'indiv'\\],\\s*filtros: \\['q', 'uf'\\], passos: \\{ para: 1, msg: 2 \\}, ganchos: false,/", $js));
        self::assertSame(1, preg_match('/if \(M\.ganchos\) \{\s*window\.McCampanha = /', $js));
    }

    /** @return list<string> */
    private function textos(string $seletor): array
    {
        return $this->doc->filter($seletor)->each(static fn (Crawler $c): string => trim((string) preg_replace('/\s+/', ' ', $c->text())));
    }

    private function css(): string
    {
        return (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/public/css/master-comercial.css');
    }
}
