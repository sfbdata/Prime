<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Controller\PastaController;
use App\Tests\Expediente\Functional\BuscaRapidaWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * A janela da Busca rápida (Ctrl + K) na página da pasta: desenho 02 EXPEDIENTES L405-421. A janela vem do
 * parcial global (layout/_globais/busca_rapida.html.twig), não do show.html.twig: a pasta a tem uma vez só.
 * O PHPUnit prova a marcação, os textos e o arranjo (com combinador de filho direto); animação, cores e o
 * teclado são do smoke. Fora da pasta: BuscaRapidaGlobalTelaTest.
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
        self::assertCount(1, $crawler->filter('.pbr'), 'a janela não se repete (só o parcial global a inclui)');

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

        // Pasta trancada para quem não é administrador: o aviso de sigilo da Lista, com "Pedir acesso".
        $sigilo = $crawler->filter('#pastaBuscaRapida > .pbr-janela > .pbr-sigilo');
        self::assertCount(1, $sigilo);
        self::assertNotNull($sigilo->attr('hidden'), 'o aviso nasce escondido');
        self::assertSame('Pasta protegida', preg_replace('/\s+/', ' ', $sigilo->filter('.pbr-sigilo-titulo')->text()));
        self::assertSame('Esta pasta contém documentos sigilosos. O acesso não é recomendado no momento.', $sigilo->filter('.pbr-sigilo-texto')->text());
        self::assertSame('Somente o usuário primário pode liberar o acesso.', $sigilo->filter('.pbr-sigilo-nota')->text());
        self::assertSame('Pedir acesso', $sigilo->filter('.pbr-sigilo > button.pbr-sigilo-pedir')->text());
        self::assertSame('/expediente/pasta/0/pedir-acesso', $raiz->attr('data-url-pedir'));
        self::assertNotSame('', (string) $raiz->attr('data-csrf-pedir'));

        $rodape = $crawler->filter('#pastaBuscaRapida > .pbr-janela > .pbr-rodape > span');
        self::assertSame(['↑ ↓ navegar', 'Enter abrir', 'Ctrl + K abrir ou fechar'], $rodape->each(static fn ($s): string => $s->text()));
    }

    #[TestDox('o token da janela serve na rota de pedir acesso da Lista (pasta trancada, quem não é administrador)')]
    public function testTokenDaJanelaPedeAcesso(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin  = $this->criarAdministrador($tenant);
        $pessoa = $this->criarUsuarioComPermissoes($tenant, ['modules.pastas.view', 'modules.expediente.view', 'resources.pasta.view']);
        $aberta = $this->criarPasta($tenant);
        $trancada = $this->criarPasta($tenant);
        $trancada->trancar($admin, new \DateTimeImmutable());
        $this->em()->flush();

        $this->logarComTenant($client, $pessoa, $tenant);
        $crawler = $client->request('GET', '/pasta/' . $aberta->getId());
        self::assertResponseIsSuccessful();
        $raiz = $crawler->filter('#pastaBuscaRapida');

        $client->xmlHttpRequest('POST', str_replace('/0/', '/' . $trancada->getId() . '/', (string) $raiz->attr('data-url-pedir')), [
            '_token' => (string) $raiz->attr('data-csrf-pedir'),
        ]);

        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('avisados', json_decode((string) $client->getResponse()->getContent(), true));
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
