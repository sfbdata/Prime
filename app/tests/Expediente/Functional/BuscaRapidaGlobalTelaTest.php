<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Functional;

use App\Expediente\Controller\ExpedienteController;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * A Busca rápida (Ctrl + K) em toda página logada: o parcial layout/_globais/busca_rapida.html.twig, incluído
 * pelo layout/_globais.html.twig do base. Prova que a janela, o CSS e o JS entram uma vez só (fora da pasta e na
 * pasta), que somem sem o módulo Pastas e que a página pública não os tem. O teclado é do smoke.
 */
#[CoversClass(ExpedienteController::class)]
final class BuscaRapidaGlobalTelaTest extends BuscaRapidaWebTestCase
{
    #[TestDox('uma página logada fora da pasta (Expediente) traz a janela, o CSS e o JS uma vez')]
    public function testPaginaForaDaPastaTemABusca(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin  = $this->criarAdministrador($tenant);

        $this->logarComTenant($client, $admin, $tenant);
        $crawler = $client->request('GET', '/expediente');
        self::assertResponseIsSuccessful();

        $raiz = $crawler->filter('#pastaBuscaRapida');
        self::assertCount(1, $raiz);
        self::assertNotNull($raiz->attr('hidden'), 'a janela nasce fechada');
        self::assertSame('/expediente/busca-rapida', $raiz->attr('data-url'));
        self::assertSame('/expediente/pasta/0/pedir-acesso', $raiz->attr('data-url-pedir'));
        self::assertNotSame('', (string) $raiz->attr('data-csrf-pedir'));
        self::assertCount(1, $crawler->filter('link[rel="stylesheet"][href$="/css/pasta-busca-rapida.css"]'));
        self::assertCount(1, $crawler->filter('script[src$="/js/pasta-busca-rapida.js"]'));
        self::assertCount(1, $crawler->filter('#pastaBuscaRapida > .pbr-janela[role="dialog"][aria-label="Busca rápida"]'));
    }

    #[TestDox('fora da pasta, o token da janela serve na rota de pedir acesso (pasta trancada, quem não é administrador)')]
    public function testTokenDaJanelaForaDaPastaPedeAcesso(): void
    {
        $client   = static::createClient();
        $tenant   = $this->criarTenant();
        $admin    = $this->criarAdministrador($tenant);
        $pessoa   = $this->criarUsuarioComPermissoes($tenant, ['modules.pastas.view', 'modules.expediente.view', 'resources.pasta.view']);
        $trancada = $this->criarPasta($tenant);
        $trancada->trancar($admin, new \DateTimeImmutable());
        $this->em()->flush();

        $this->logarComTenant($client, $pessoa, $tenant);
        $crawler = $client->request('GET', '/expediente');
        self::assertResponseIsSuccessful();
        $raiz = $crawler->filter('#pastaBuscaRapida');
        self::assertCount(1, $raiz);

        $client->xmlHttpRequest('POST', str_replace('/0/', '/' . $trancada->getId() . '/', (string) $raiz->attr('data-url-pedir')), [
            '_token' => (string) $raiz->attr('data-csrf-pedir'),
        ]);

        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('avisados', json_decode((string) $client->getResponse()->getContent(), true));
    }

    #[TestDox('sem o módulo Pastas, a página logada não traz a janela, o CSS nem o JS')]
    public function testSemModuloPastasNaoTemABusca(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $pessoa = $this->criarUsuarioComPermissoes($tenant, ['modules.expediente.view']);

        $this->logarComTenant($client, $pessoa, $tenant);
        $crawler = $client->request('GET', '/expediente');
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('#pastaBuscaRapida'));
        self::assertCount(0, $crawler->filter('link[href$="/css/pasta-busca-rapida.css"]'));
        self::assertCount(0, $crawler->filter('script[src$="/js/pasta-busca-rapida.js"]'));
    }

    #[TestDox('a página pública de login não traz a busca')]
    public function testLoginNaoTemABusca(): void
    {
        $client  = static::createClient();
        $crawler = $client->request('GET', '/login');
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('#pastaBuscaRapida'));
        self::assertCount(0, $crawler->filter('script[src$="/js/pasta-busca-rapida.js"]'));
    }
}
