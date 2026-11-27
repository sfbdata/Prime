<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Functional;

use App\Comercial\Envio\LayoutDoEmail;
use App\Comercial\Envio\MensagemDeEmail;
use App\Comercial\Envio\TipoDeEnvio;
use App\Comercial\Service\MontadorDeEmail;
use App\Tests\Comercial\Support\MontaOMontador;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Os layouts `linhas` e `cru` do montador (spec §8.3): as linhas entram direto na tabela de 600 px, depois do
 * cabeçalho (dc L.497-499); o cru sai sem o cabeçalho azul, sanitizado de novo na montagem, com o `{nome}` trocado
 * depois da sanitização pelo nome escapado, e com o rodapé legal (B3).
 */
#[CoversClass(MontadorDeEmail::class)]
final class LayoutsDoMontadorTest extends KernelTestCase
{
    use MontaOMontador;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    private function html(string $corpo, LayoutDoEmail $layout, string $nome = 'Ana Souza'): string
    {
        $m = new MensagemDeEmail('ana@exemplo.com', 'Assunto', $corpo, TipoDeEnvio::Lista, 'teste_campanha', 'comercial_campanhas', nome: $nome, selo: 'SELO', titulo: 'Olá, {nome}', layout: $layout);

        return (string) $this->montador()->montar($m, 'previa')->getHtmlBody();
    }

    #[TestDox('linhas: o corpo são linhas filhas diretas da tabela de 600 px, depois do cabeçalho e antes do rodapé')]
    public function testLinhas(): void
    {
        $doc = new Crawler($this->html('<tr><td class="b1">Um</td></tr><tr><td class="b2">Dois</td></tr>', LayoutDoEmail::Linhas));
        $linhas = $doc->filter('table[width="600"] > tbody > tr, table[width="600"] > tr');

        self::assertGreaterThanOrEqual(4, $linhas->count());
        self::assertSame(1, $linhas->eq(0)->filter('td[data-cabecalho]')->count(), 'o cabeçalho primeiro');
        self::assertSame('Um', $linhas->eq(1)->filter('td.b1')->text());
        self::assertSame('Dois', $linhas->eq(2)->filter('td.b2')->text());
        self::assertSame(1, $linhas->eq(3)->filter('td.rodape-legal')->count(), 'o rodapé legal por último');
        self::assertSame('Olá, Ana', $doc->filter('td[data-cabecalho] div[data-titulo]')->text());
    }

    #[TestDox('cru: sem o cabeçalho, script e evento caem na montagem, {nome} escapado depois, rodapé legal sempre')]
    public function testCru(): void
    {
        $html = $this->html('<p onclick="x()">Oi, {nome}</p><script>alert(1)</script><a href="javascript:alert(1)">a</a>', LayoutDoEmail::Cru, '<b>Eva</b> Lima');

        self::assertStringNotContainsString('data-cabecalho', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('onclick', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringContainsString('<p>Oi, &lt;B&gt;Eva&lt;/B&gt;</p>', $html, 'o nome entra escapado, depois da sanitização');
        $rodape = (new Crawler($html))->filter('table[data-rodape-cru] td.rodape-legal');
        self::assertSame(1, $rodape->count());
        self::assertStringContainsString('CNPJ 66.803.163/0001-10', $rodape->text());
        self::assertSame(1, $rodape->filter('a[data-descadastro]')->count());
        self::assertSame(1, $rodape->filter('a[data-politica]')->count());
    }

    #[TestDox('Visual suave (M5.4): faixa azul fina, logo colorido, selo como etiqueta, título de 22px, sem o bloco em degradê')]
    public function testVisualSuave(): void
    {
        $doc = new Crawler($this->html('<p>Corpo</p>', LayoutDoEmail::Padrao));
        $cartao = $doc->filter('table[width="600"]');
        $cabecalho = $doc->filter('table[width="600"] > tbody > tr > td[data-cabecalho], table[width="600"] > tr > td[data-cabecalho]');

        self::assertStringContainsString('border-radius:4px', (string) $cartao->attr('style'));
        self::assertStringContainsString('box-shadow:', (string) $cartao->attr('style'));
        self::assertSame(1, $cabecalho->count());
        self::assertSame(1, $cabecalho->filter('td[height="4"][bgcolor="#0a6fa0"]')->count(), 'a faixa fina azul no topo');
        self::assertStringEndsWith('/images/logo/bluejus-logo-email.png', (string) $cabecalho->filter('img[alt="BlueJus"]')->attr('src'));
        self::assertSame('SELO', $cabecalho->filter('span[data-selo]')->text());
        self::assertStringContainsString('font:bold 22px', (string) $cabecalho->filter('div[data-titulo]')->attr('style'));
        self::assertStringContainsString('color:#5f7684', (string) $doc->filter('td.rodape-legal')->attr('style'), 'o texto de apoio do Guia (4,8:1)');
        self::assertStringNotContainsString('linear-gradient', $doc->html());
    }

    #[TestDox('Sem selo e sem título, o cabeçalho não deixa espaço vazio')]
    public function testSemSeloNemTitulo(): void
    {
        $m = new MensagemDeEmail('ana@exemplo.com', 'Assunto', '<p>x</p>', TipoDeEnvio::Lista, 'teste_campanha', 'comercial_campanhas', nome: 'Ana');
        $doc = new Crawler((string) $this->montador()->montar($m, 'previa')->getHtmlBody());

        self::assertSame(1, $doc->filter('td[data-cabecalho]')->count());
        self::assertSame(0, $doc->filter('[data-selo], [data-titulo]')->count());
    }

    #[TestDox('Versão texto com o layout novo: selo e título em linhas próprias, sem linha só de espaço das células espaçadoras')]
    public function testVersaoTexto(): void
    {
        $m = new MensagemDeEmail('ana@exemplo.com', 'Assunto', '<p>Corpo</p>', TipoDeEnvio::Lista, 'teste_campanha', 'comercial_campanhas', nome: 'Ana Souza', selo: 'NOVIDADE', titulo: 'Olá, {nome}');
        $texto = (string) $this->montador()->montar($m, 'previa')->getTextBody();
        $linhas = explode("\n", $texto);

        self::assertContains('NOVIDADE', $linhas);
        self::assertContains('Olá, Ana', $linhas);
        foreach ($linhas as $linha) {
            self::assertDoesNotMatchRegularExpression('/^[\s\x{00A0}]+$/u', $linha);
        }
        self::assertStringNotContainsString("\n\n\n", $texto);
    }
}
