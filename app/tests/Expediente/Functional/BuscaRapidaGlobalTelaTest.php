<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Functional;

use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\TenantRole;
use App\Twig\PermissionExtension;
use App\Twig\TenantContextExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * A Busca rápida (Ctrl + K) em toda página logada: o parcial layout/_globais/busca_rapida.html.twig, incluído
 * pelo layout/_globais.html.twig do base. Prova que a janela, o CSS e o JS entram uma vez só numa tela sem relação
 * com Expediente nem Pasta (Agenda), com o "Pedir acesso" funcionando; que somem sem o módulo Pastas, sem
 * escritório na sessão e na página pública. A pasta (uma vez só) fica em PastaBuscaRapidaTelaTest. O teclado é do
 * smoke. Cobre as duas funções Twig que decidem a condição do parcial: can_access_module e current_tenant.
 */
#[CoversClass(PermissionExtension::class)]
#[CoversClass(TenantContextExtension::class)]
final class BuscaRapidaGlobalTelaTest extends BuscaRapidaWebTestCase
{
    #[TestDox('uma tela logada sem relação com Expediente nem Pasta (Agenda) traz a janela, o CSS e o JS uma vez')]
    public function testTelaDaAgendaTemABusca(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $admin  = $this->criarAdministrador($tenant);

        $this->logarComTenant($client, $admin, $tenant);
        $crawler = $client->request('GET', '/agenda');
        self::assertResponseIsSuccessful();

        $raiz = $crawler->filter('#pastaBuscaRapida');
        self::assertCount(1, $raiz);
        self::assertCount(1, $crawler->filter('.pbr'));
        self::assertNotNull($raiz->attr('hidden'), 'a janela nasce fechada');
        self::assertSame('/expediente/busca-rapida', $raiz->attr('data-url'));
        // Sem pedido de acesso próprio: pasta oculta abre a tela "Pasta trancada" do cadeado (#120).
        self::assertNull($raiz->attr('data-url-pedir'));
        self::assertNull($raiz->attr('data-csrf-pedir'));
        self::assertCount(1, $crawler->filter('link[rel="stylesheet"][href$="/css/pasta-busca-rapida.css"]'));
        self::assertCount(1, $crawler->filter('script[src$="/js/pasta-busca-rapida.js"]'));
        self::assertCount(1, $crawler->filter('#pastaBuscaRapida > .pbr-janela[role="dialog"][aria-label="Busca rápida"]'));
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

    #[TestDox('logado sem escritório na sessão (tela de escolher escritório), não há busca')]
    public function testSemEscritorioNaSessaoNaoTemABusca(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $outro  = $this->criarTenant();
        $admin  = $this->criarAdministrador($tenant);

        // Segundo vínculo, para a tela de escolher escritório não escolher sozinha.
        $papel = new TenantRole();
        $papel->setTenant($outro);
        $papel->setName('Papel Busca Rapida ' . uniqid());
        $papel->setIsSystem(true);
        $this->em()->persist($papel);
        $vinculo = new UserTenant($admin, $outro);
        $vinculo->setTenantRole($papel);
        $this->em()->persist($vinculo);
        $this->em()->flush();

        $client->loginUser($admin);
        $this->marcarTermosAceitos($client);
        $crawler = $client->request('GET', '/escritorio/selecionar');
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('.app-wrapper'), 'a tela é logada (base com usuário)');
        self::assertCount(0, $crawler->filter('#pastaBuscaRapida'));
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
