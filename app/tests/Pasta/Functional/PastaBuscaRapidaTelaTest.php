<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Tests\Expediente\Functional\BuscaRapidaWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * A janela da Busca rápida (Ctrl + K) na página da pasta: desenho 02 EXPEDIENTES L405-421. O PHPUnit
 * prova a marcação, os textos e o arranjo (com combinador de filho direto); animação, cores e o
 * teclado são do smoke.
 */
#[CoversClass(PastaController::class)]
final class PastaBuscaRapidaTelaTest extends BuscaRapidaWebTestCase
{
    #[TestDox('a pasta carrega o CSS, o JS e a janela fechada com os textos do desenho')]
    public function testJanelaComOsTextosDoDesenho(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin  = $this->criarAdministrador($tenant);
        $pasta  = $this->criarPasta($tenant);

        $this->logarComTenant($client, $admin, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('link[rel="stylesheet"][href$="/css/pasta-busca-rapida.css"]'));
        self::assertCount(1, $crawler->filter('script[src$="/js/pasta-busca-rapida.js"]'));

        $raiz = $crawler->filter('#pastaBuscaRapida');
        self::assertCount(1, $raiz);
        self::assertNotNull($raiz->attr('hidden'), 'a janela nasce fechada');
        self::assertSame('/expediente/busca-rapida', $raiz->attr('data-url'));

        self::assertCount(1, $crawler->filter('#pastaBuscaRapida > .pbr-fundo'));
        $janela = $crawler->filter('#pastaBuscaRapida > .pbr-janela[role="dialog"][aria-label="Busca rápida"]');
        self::assertCount(1, $janela);

        $campo = $crawler->filter('#pastaBuscaRapida > .pbr-janela > .pbr-topo > input.pbr-campo');
        self::assertSame('Buscar pasta, cliente, CPF ou processo', $campo->attr('placeholder'));
        self::assertSame('Buscar pasta, cliente, CPF ou processo', $campo->attr('aria-label'));
        self::assertCount(1, $crawler->filter('#pastaBuscaRapida > .pbr-janela > .pbr-topo > i.bi-search'));
        self::assertSame('Esc', $crawler->filter('#pastaBuscaRapida > .pbr-janela > .pbr-topo > .pbr-esc')->text());

        $vazio = $crawler->filter('#pastaBuscaRapida > .pbr-janela > .pbr-lista > .pbr-vazio');
        self::assertSame('Nada encontrado.', $vazio->text());
        self::assertCount(1, $vazio->filter('i.bi-search'));

        $lista = $crawler->filter('#pastaBuscaRapida > .pbr-janela > .pbr-lista > .pbr-opcoes#pbrOpcoes[role="listbox"]');
        self::assertCount(1, $lista);
        self::assertSame('combobox', $campo->attr('role'));
        self::assertSame('pbrOpcoes', $campo->attr('aria-controls'));

        // Pasta oculta pelo cadeado abre na própria tela trancada (com o pedido de lá): a janela não tem aviso próprio.
        self::assertCount(0, $crawler->filter('#pastaBuscaRapida .pbr-sigilo'));
        self::assertNull($raiz->attr('data-url-pedir'));
        self::assertNull($raiz->attr('data-csrf-pedir'));

        $rodape = $crawler->filter('#pastaBuscaRapida > .pbr-janela > .pbr-rodape > span');
        self::assertSame(['↑ ↓ navegar', 'Enter abrir', 'Ctrl + K abrir ou fechar'], $rodape->each(static fn ($s): string => $s->text()));
    }

    #[TestDox('sem o módulo Pastas, a página da pasta não traz a janela nem o JS')]
    public function testSemModuloPastasNaoTrazAJanela(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $pessoa = $this->criarUsuarioComPermissoes($tenant, ['resources.pasta.view']);
        $pasta  = $this->criarPasta($tenant);

        $this->logarComTenant($client, $pessoa, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('#pastaBuscaRapida'));
        self::assertCount(0, $crawler->filter('script[src$="/js/pasta-busca-rapida.js"]'));
    }
}
