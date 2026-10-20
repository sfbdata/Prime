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
 * O arranjo do bloco "Nova campanha de e-mail" no parcial do /master contra o desenho 05 (dc L.229-260), com
 * combinador de filho direto: a posição antes das Automações, a grade em duas colunas (1 · MODELO e 3 · MENSAGEM à
 * esquerda, PRÉVIA à direita), a ordem dos botões e os textos literais. O que é da 2b (2 · PARA QUEM, Frequência,
 * Agendar, "Enviar campanha") não aparece.
 */
#[CoversNothing]
final class ParcialDoComercialTest extends KernelTestCase
{
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
        $html = static::getContainer()->get(Environment::class)->render('master/_comercial.html.twig');
        $this->doc = new Crawler('<!doctype html><html><body>' . $html . '</body></html>');
    }

    /** @return list<string> */
    private function textos(string $seletor): array
    {
        return $this->doc->filter($seletor)->each(static fn (Crawler $c): string => trim((string) preg_replace('/\s+/', ' ', $c->text())));
    }

    #[TestDox('A campanha vem antes das Automações, como no dc (L.229 e L.262), com o cabeçalho literal')]
    public function testPosicaoECabecalho(): void
    {
        self::assertSame(['mc-cp-titulo', 'mc-auto-titulo'], $this->doc->filter('[data-mc] > section')->each(static fn (Crawler $c): string => (string) $c->attr('aria-labelledby')));
        self::assertSame(1, $this->doc->filter('section.mc-cp > .mc-cabeca > i.bi-megaphone')->count());
        self::assertSame(['Nova campanha de e-mail'], $this->textos('section.mc-cp > .mc-cabeca > b'));
        self::assertSame(['convites, ofertas, agradecimentos e novidades · individual ou em grande escala'], $this->textos('section.mc-cp > .mc-cabeca > span'));
    }

    #[TestDox('Duas colunas na grade (L.231): 1 · MODELO e 3 · MENSAGEM à esquerda, PRÉVIA DO E-MAIL à direita')]
    public function testDuasColunas(): void
    {
        self::assertSame(2, $this->doc->filter('section.mc-cp > .mc-cp-grade > .mc-cp-col')->count());
        self::assertSame(['1 · MODELO', '3 · MENSAGEM'], $this->textos('section.mc-cp > .mc-cp-grade > .mc-cp-editor > .mc-cp-passo'));
        self::assertSame(['PRÉVIA DO E-MAIL'], $this->textos('section.mc-cp > .mc-cp-grade > .mc-cp-lado > .mc-cp-passo'));
        self::assertSame('mc-cp-col mc-cp-editor', $this->doc->filter('section.mc-cp > .mc-cp-grade > .mc-cp-col')->eq(0)->attr('class'));
        $css = (string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir') . '/public/css/master-comercial.css');
        self::assertStringContainsString('.mc-cp-grade { padding: 16px 18px; display: grid; grid-template-columns: repeat(auto-fit, minmax(min(340px, 100%), 1fr)); gap: 18px; align-items: start; }', $css, 'os 340px do dc, sem passar da caixa em tela estreita');
    }

    #[TestDox('As ações do modelo na ordem do dc (L.235); Salvar e Excluir só para o próprio')]
    public function testAcoesDoModelo(): void
    {
        self::assertSame(['Novo modelo', 'Duplicar', 'Importar HTML', 'Salvar modelo', 'Excluir'], $this->textos('.mc-cp-editor > .mc-cp-acoes > .mc-cp-acao'));
        self::assertSame(2, $this->doc->filter('.mc-cp-editor > .mc-cp-acoes > [data-mc-cp-so-proprio][hidden]')->count());
        self::assertSame('.html,.htm', $this->doc->filter('.mc-cp-acoes > label > input[type="file"]')->attr('accept'));
    }

    #[TestDox('Os campos da mensagem (L.242-247) com os rótulos e as dicas literais')]
    public function testCamposDaMensagem(): void
    {
        $rotulos = $this->doc->filter('.mc-cp-editor > label.mc-cp-campo')->each(static fn (Crawler $c): string => trim((string) $c->getNode(0)?->firstChild?->textContent));
        self::assertSame(['ASSUNTO', 'TÍTULO', 'NOME DO MODELO', 'HTML IMPORTADO'], $rotulos);
        self::assertSame(['use {nome} para o primeiro nome'], $this->textos('[data-mc-cp-simples] > label > .mc-cp-dica'));
        self::assertSame(['monte o layout; a prévia atualiza na hora'], $this->textos('[data-mc-cp-blocos] > .mc-cp-blocos-titulo > .mc-cp-dica'));
    }

    #[TestDox('A prévia é um iframe sandbox="" e os botões são "Baixar HTML" e "Enviar teste"; o que é da 2b não aparece')]
    public function testPreviaEBotoes(): void
    {
        $quadro = $this->doc->filter('.mc-cp-lado > iframe.mc-cp-previa');
        self::assertSame(['', 'Prévia do e-mail'], [$quadro->attr('sandbox'), $quadro->attr('title')]);
        self::assertSame(['Baixar HTML', 'Enviar teste'], $this->textos('.mc-cp-lado > .mc-cp-botoes > button'));
        self::assertSame(1, $this->doc->filter('.mc-cp-lado > .mc-cp-botoes > button > i.bi-filetype-html')->count());
        self::assertSame(1, $this->doc->filter('.mc-cp-lado > .mc-cp-botoes > button > i.bi-send-check')->count());
        $texto = $this->doc->filter('section.mc-cp')->text();
        foreach (['PARA QUEM', 'Enviar campanha', 'Frequência', 'Agendar'] as $da2b) {
            self::assertStringNotContainsString($da2b, $texto);
        }
        self::assertStringStartsWith('LGPD: só recebe quem se cadastrou ou autorizou.', $this->textos('.mc-cp-lado > .mc-cp-lgpd')[0]);
    }

    #[TestDox('Sem script inline (CSP do /master): o bloco traz o próprio arquivo de JS')]
    public function testSemScriptInline(): void
    {
        $scripts = $this->doc->filter('script');
        self::assertSame(0, $scripts->reduce(static fn (Crawler $s): bool => $s->attr('src') === null)->count());
        self::assertSame(1, $this->doc->filter('script[src*="js/master/comercial-campanha.js"]')->count());
    }
}
