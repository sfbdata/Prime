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
        $client->request('GET', '/push-processual');

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

        $client->request('GET', '/push-processual');

        self::assertResponseStatusCodeSame(302);
        self::assertStringNotContainsString('/push-processual', (string) $client->getResponse()->headers->get('Location'));
    }

    #[Test]
    public function indexComoGestorRetorna200(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com');
        $this->logarComTenant($client, $gestor, $tenant);
        $this->limparIdentityMap();

        $client->request('GET', '/push-processual');

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

        $client->request('GET', '/push-processual/oabs');

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

        $crawler = $client->request('GET', '/push-processual/oabs');
        $form = $crawler->selectButton('Adicionar')->form();
        $form['oab_monitorada[numero]'] = '67228';
        $form['oab_monitorada[uf]'] = 'PR';
        $form['oab_monitorada[apelido]'] = 'Dra. Teste';
        $client->submit($form);

        self::assertResponseRedirects('/push-processual/oabs');

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

        $client->request('GET', '/push-processual/' . $pubId);

        self::assertResponseIsSuccessful();
    }

    /**
     * O módulo se chama "Push Processual" desde `Version20260819160000`, mas a renomeação passou por
     * cima do título desta tela: o `<h1>` continuou dizendo "Publicação do DJEN" por dias, com o
     * `{% block title %}` da MESMA tela já correto — divergência que nenhum teste via.
     *
     * O buraco tinha endereço: `RotasLegadasDjenControllerTest` confere o `<h1>` da LISTAGEM, e só
     * dela. Aqui a tela de detalhe passa a ter a mesma trava.
     *
     * A asserção é sobre o nome do MÓDULO, não sobre a frase inteira — reescrever "Publicação" para
     * "Comunicação" é decisão de rótulo, e não deve quebrar teste; voltar a chamar o módulo de DJEN,
     * sim. Por isso `assertStringNotContainsString('DJEN')` acompanha: é o texto que já vazou.
     */
    #[Test]
    public function showExibeONomeNovoDoModuloNoTitulo(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant();
        $gestor = $this->criarGestor($tenant, 'g_' . uniqid() . '@test.com');
        $pub = $this->criarPublicacao($tenant, '900002', '50636766220224047000');
        $pubId = (int) $pub->getId();
        $this->logarComTenant($client, $gestor, $tenant);
        $this->limparIdentityMap();

        $crawler = $client->request('GET', '/push-processual/' . $pubId);

        self::assertResponseIsSuccessful();

        $titulo = $crawler->filter('h1')->text();
        self::assertStringContainsString('Push Processual', $titulo);
        self::assertStringNotContainsString('DJEN', $titulo, 'o título da tela voltou ao nome antigo do módulo');
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

        $crawler = $client->request('GET', '/push-processual');
        $form = $crawler->selectButton('Sincronizar agora')->form();
        $client->submit($form);

        self::assertResponseRedirects('/push-processual');
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

        $client->request('POST', '/push-processual/oabs/' . $id . '/alternar', ['_token' => 'invalido']);
        self::assertResponseRedirects('/push-processual/oabs');

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

        $crawler = $client->request('GET', '/push-processual/oabs');
        $client->submit($crawler->selectButton('Pausar')->form());
        self::assertResponseRedirects('/push-processual/oabs');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertFalse($em->find(OabMonitorada::class, $id)->isAtivo(), 'token válido pausa a OAB');
    }
}
