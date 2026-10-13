<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Cliente\Entity\ClientePF;
use App\Controller\PastaController;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * PAS-001 — o nome do cliente (h1 do cabeçalho) aceita o botão direito e abre a janela
 * "Detalhes do cliente" (pasta-clientes.js, pelo `data-cliente-id` do próprio h1), com a dica do
 * desenho (dc L.1136: ícone `bi-mouse2`, "Detalhes: clique com o botão direito"). Pasta sem
 * cliente cadastrado não tem janela para abrir: nem gancho, nem dica.
 */
#[CoversClass(PastaController::class)]
#[Group('pasta')]
final class PastaTituloClienteBotaoDireitoTelaTest extends JusPrimeWebTestCase
{
    use CriaFixturesPushDaPastaTrait;

    private function abrir(KernelBrowser $client, Pasta $pasta): Crawler
    {
        $this->em()->clear();
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    #[TestDox('com cliente: o h1 leva o id do cliente principal e aponta para a dica, filha direta do bloco do cliente')]
    public function testComCliente(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);

        $cliente = new ClientePF();
        $cliente->setTenant($tenant);
        $cliente->setEmail('pas001' . uniqid() . '@test.com');
        $cliente->setCep('70000-000');
        $cliente->setEndereco('SQS 110 Bloco A');
        $cliente->setCidade('Brasília');
        $cliente->setEstado('DF');
        $cliente->setNomeCompleto('Ana Cliente');
        $cliente->setCpf('11122233344');
        $cliente->setRg('1234567');
        $cliente->setRgOrgaoExpedidor('SSP/DF');
        $this->em()->persist($cliente);
        $pasta->addCliente($cliente);
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $titulo = $crawler->filter('.ps-cabecalho > .ps-cab-cliente > .ps-cab-cliente-corpo > .ps-cab-cliente-linha > h1.ps-cab-titulo');
        self::assertCount(1, $titulo);
        self::assertSame((string) $cliente->getId(), $titulo->attr('data-cliente-id'));
        self::assertStringNotContainsString('js-ps-cli-detalhes', (string) $titulo->attr('class'), 'o clique comum não abre a janela');

        $dica = $crawler->filter('.ps-cabecalho > .ps-cab-cliente > span.ps-cab-dica[role="tooltip"]');
        self::assertCount(1, $dica);
        self::assertSame($dica->attr('id'), $titulo->attr('aria-describedby'));
        self::assertSame('Detalhes: clique com o botão direito', trim($dica->text()));
        self::assertCount(1, $dica->filter('i.bi.bi-mouse2'));

        // A janela pega a URL do resumo no trilho de clientes, que precisa existir.
        self::assertCount(1, $crawler->filter('[data-trilho="clientes"][data-resumo-url]'));
    }

    #[TestDox('sem cliente cadastrado (só o identificador): sem gancho e sem dica')]
    public function testSemCliente(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarAdmin();
        $pasta           = $this->criarPasta($tenant);
        $pasta->setNomeCliente('MARIA DAS GRACAS');
        $this->em()->flush();

        $this->logarComTenant($client, $user, $tenant);
        $crawler = $this->abrir($client, $pasta);

        $titulo = $crawler->filter('.ps-cab-cliente-linha > h1.ps-cab-titulo');
        self::assertCount(1, $titulo);
        self::assertNull($titulo->attr('data-cliente-id'));
        self::assertNull($titulo->attr('aria-describedby'));
        self::assertCount(0, $crawler->filter('.ps-cab-dica'));
    }
}
