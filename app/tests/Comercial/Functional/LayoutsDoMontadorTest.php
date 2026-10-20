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
        self::assertStringContainsString('linear-gradient', (string) $linhas->eq(0)->html(), 'o cabeçalho do dc primeiro');
        self::assertSame('Um', $linhas->eq(1)->filter('td.b1')->text());
        self::assertSame('Dois', $linhas->eq(2)->filter('td.b2')->text());
        self::assertSame(1, $linhas->eq(3)->filter('td.rodape-legal')->count(), 'o rodapé legal por último');
        self::assertSame('Olá, Ana', $doc->filter('div[style*="font:bold 24px"]')->text());
    }

    #[TestDox('cru: sem o cabeçalho azul, script e evento caem na montagem, {nome} escapado depois, rodapé legal sempre')]
    public function testCru(): void
    {
        $html = $this->html('<p onclick="x()">Oi, {nome}</p><script>alert(1)</script><a href="javascript:alert(1)">a</a>', LayoutDoEmail::Cru, '<b>Eva</b> Lima');

        self::assertStringNotContainsString('linear-gradient', $html);
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('onclick', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringContainsString('<p>Oi, &lt;b&gt;Eva&lt;/b&gt;</p>', $html, 'o nome entra escapado, depois da sanitização');
        $rodape = (new Crawler($html))->filter('table[data-rodape-cru] td.rodape-legal');
        self::assertSame(1, $rodape->count());
        self::assertStringContainsString('CNPJ 66.803.163/0001-10', $rodape->text());
        self::assertSame(1, $rodape->filter('a[data-descadastro]')->count());
        self::assertSame(1, $rodape->filter('a[data-politica]')->count());
    }
}
