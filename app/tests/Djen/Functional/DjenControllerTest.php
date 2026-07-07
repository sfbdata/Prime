<?php

declare(strict_types=1);

namespace App\Tests\Djen\Functional;

use App\Djen\DTO\RespostaComunicacoes;
use App\Djen\Entity\OabMonitorada;
use App\Djen\Repository\OabMonitoradaRepository;
use App\Djen\Service\DjenClient;
use App\Djen\Service\DjenClientInterface;
use App\Entity\Tenant\Tenant;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;

final class DjenControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesDjenTrait;

    #[Test]
    public function indexNaoAutenticadoRedirecionaParaLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/djen');

        self::assertResponseRedirects();
        self::assertStringContainsString('login', (string) $client->getResponse()->headers->get('Location'));
    }

    #[Test]
    public function indexSemAcessoAoModuloRedireciona(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $comum = $this->criarUsuarioComum($tenant);
        $this->logarComTenant($client, $comum, $tenant);
        $this->limparIdentityMap();

        $client->request('GET', '/djen');

        self::assertResponseStatusCodeSame(302);
        self::assertStringNotContainsString('/djen', (string) $client->getResponse()->headers->get('Location'));
    }

    #[Test]
    public function indexComoGestorRetorna200(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com');
        $this->logarComTenant($client, $gestor, $tenant);
        $this->limparIdentityMap();

        $client->request('GET', '/djen');

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function oabsComoGestorRetorna200(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com');
        $this->logarComTenant($client, $gestor, $tenant);
        $this->limparIdentityMap();

        $client->request('GET', '/djen/oabs');

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function adicionarOabPersisteERedireciona(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $tenantId = (int) $tenant->getId();
        $gestor = $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com');
        $this->logarComTenant($client, $gestor, $tenant);
        $this->limparIdentityMap();

        $crawler = $client->request('GET', '/djen/oabs');
        $form = $crawler->selectButton('Adicionar')->form();
        $form['oab_monitorada[numero]'] = '67228';
        $form['oab_monitorada[uf]'] = 'PR';
        $form['oab_monitorada[apelido]'] = 'Dra. Teste';
        $client->submit($form);

        self::assertResponseRedirects('/djen/oabs');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $tenantFresh = $em->find(Tenant::class, $tenantId);
        $oab = static::getContainer()->get(OabMonitoradaRepository::class)
            ->findByNumeroUfDoTenant('67228', 'PR', $tenantFresh);

        self::assertNotNull($oab, 'a OAB deveria ter sido persistida');
        self::assertSame('Dra. Teste', $oab->getApelido());
    }

    #[Test]
    public function showPublicacaoDoTenantRetorna200(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com');
        $pub = $this->criarPublicacao($tenant, '900001', '50636766220224047000');
        $pubId = (int) $pub->getId();
        $this->logarComTenant($client, $gestor, $tenant);
        $this->limparIdentityMap();

        $client->request('GET', '/djen/' . $pubId);

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function sincronizarAgoraRedirecionaComClienteMockado(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com');
        $this->criarOab($tenant, '67228', 'PR');

        $mock = $this->createMock(DjenClientInterface::class);
        $mock->method('consultarComunicacoes')->willReturn(new RespostaComunicacoes(0, []));
        static::getContainer()->set(DjenClient::class, $mock);

        $this->logarComTenant($client, $gestor, $tenant);
        $this->limparIdentityMap();

        $crawler = $client->request('GET', '/djen');
        $form = $crawler->selectButton('Sincronizar agora')->form();
        $client->submit($form);

        self::assertResponseRedirects('/djen');
    }

    #[Test]
    public function alternarOabDoProprioTenantComTokenInvalidoNaoMuda(): void
    {
        // Guarda a propriedade central da reordenação find-before-CSRF: token inválido NÃO muta,
        // mesmo sendo a OAB do próprio escritório. Se alguém remover o CSRF ou mover a mutação
        // para antes dele, este teste falha (a OAB seria pausada).
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com');
        $oab = $this->criarOab($tenant, '67228', 'PR');
        $id = (int) $oab->getId();
        $this->logarComTenant($client, $gestor, $tenant);
        $this->limparIdentityMap();

        $client->request('POST', '/djen/oabs/' . $id . '/alternar', ['_token' => 'invalido']);
        self::assertResponseRedirects('/djen/oabs');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertTrue($em->find(OabMonitorada::class, $id)->isAtivo(), 'token inválido não pode alternar o status');
    }

    #[Test]
    public function alternarOabDoProprioTenantComTokenValidoPausa(): void
    {
        // Happy path HTTP do caminho pós-CSRF: o token válido vem do form renderizado.
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com');
        $oab = $this->criarOab($tenant, '67228', 'PR');
        $id = (int) $oab->getId();
        $this->logarComTenant($client, $gestor, $tenant);
        $this->limparIdentityMap();

        $crawler = $client->request('GET', '/djen/oabs');
        $client->submit($crawler->selectButton('Pausar')->form());
        self::assertResponseRedirects('/djen/oabs');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertFalse($em->find(OabMonitorada::class, $id)->isAtivo(), 'token válido pausa a OAB');
    }
}
