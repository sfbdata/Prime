<?php

declare(strict_types=1);

namespace App\Tests\Djen\Functional;

use App\Djen\Entity\OabMonitorada;
use App\Djen\Entity\PublicacaoDjen;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Isolamento multi-tenant do DJEN (inegociável). Papéis isSystem(true) provam que quem fecha o
 * vazamento é a busca tenant-safe na camada de dados — não a permissão.
 */
final class DjenIsolamentoControllerTest extends JusPrimeWebTestCase
{
    use CriaFixturesDjenTrait;

    #[Test]
    #[TestDox('Dono vê a própria publicação; usuário de outro escritório recebe 404')]
    public function showIsolaPublicacaoPorTenant(): void
    {
        $client = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $gestorA = $this->criarGestor($tenantA, 'a_' . uniqid() . '@test.com');
        $gestorB = $this->criarGestor($tenantB, 'b_' . uniqid() . '@test.com');
        $pubB = $this->criarPublicacao($tenantB, '910001', '50636766220224047000');
        $id = (int) $pubB->getId();
        $this->limparIdentityMap();

        $this->logarComTenant($client, $gestorB, $tenantB);
        $client->request('GET', '/djen/' . $id);
        self::assertResponseIsSuccessful();

        $this->logarComTenant($client, $gestorA, $tenantA);
        $client->request('GET', '/djen/' . $id);
        self::assertResponseStatusCodeSame(404, 'show não pode revelar publicação de outro escritório');
    }

    #[Test]
    #[TestDox('A listagem de um escritório não exibe publicações de outro')]
    public function indexNaoVazaPublicacaoDeOutroTenant(): void
    {
        $client = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $gestorA = $this->criarGestor($tenantA, 'a_' . uniqid() . '@test.com');
        $this->criarPublicacao($tenantA, '920001', '11111111111111111111');
        $this->criarPublicacao($tenantB, '920002', '22222222222222222222');
        $this->limparIdentityMap();

        $this->logarComTenant($client, $gestorA, $tenantA);
        $client->request('GET', '/djen');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('11111111111111111111', $html);
        self::assertStringNotContainsString('22222222222222222222', $html);
    }

    #[Test]
    #[TestDox('O 404 cross-tenant é isolamento, não exclusão: a publicação de B continua existindo')]
    public function publicacaoDeOutroTenantContinuaExistindo(): void
    {
        $client = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $gestorA = $this->criarGestor($tenantA, 'a_' . uniqid() . '@test.com');
        $pubB = $this->criarPublicacao($tenantB, '930001', '50636766220224047000');
        $id = (int) $pubB->getId();
        $this->limparIdentityMap();

        $this->logarComTenant($client, $gestorA, $tenantA);
        $client->request('GET', '/djen/' . $id);
        self::assertResponseStatusCodeSame(404);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        if ($em->getFilters()->isEnabled('tenant')) {
            $em->getFilters()->disable('tenant');
        }
        $em->clear();
        self::assertNotNull($em->find(PublicacaoDjen::class, $id), 'a publicação de B deve continuar existindo');
    }

    #[Test]
    #[TestDox('Usuário de A não consegue remover a OAB monitorada de B')]
    public function removerOabDeOutroTenantNaoApagaODado(): void
    {
        $client = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $gestorA = $this->criarGestor($tenantA, 'a_' . uniqid() . '@test.com');
        $oabB = $this->criarOab($tenantB, '99999', 'SP');
        $id = (int) $oabB->getId();
        $this->limparIdentityMap();

        $this->logarComTenant($client, $gestorA, $tenantA);
        // O guard tenant-safe roda ANTES do CSRF: cross-tenant é 404 determinístico (mesmo com token inválido).
        $client->request('POST', '/djen/oabs/' . $id . '/remover', ['_token' => 'irrelevante']);
        self::assertResponseStatusCodeSame(404, 'guard IDOR: remover OAB de outro escritório é 404');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        if ($em->getFilters()->isEnabled('tenant')) {
            $em->getFilters()->disable('tenant');
        }
        $em->clear();
        self::assertNotNull($em->find(OabMonitorada::class, $id), 'a OAB de B não pode ter sido apagada por A');
    }

    #[Test]
    #[TestDox('Usuário de A não consegue alternar o status da OAB de B (404); status de B intacto')]
    public function alternarOabDeOutroTenantRetorna404(): void
    {
        $client = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $gestorA = $this->criarGestor($tenantA, 'a_' . uniqid() . '@test.com');
        $oabB = $this->criarOab($tenantB, '88888', 'RJ');
        $id = (int) $oabB->getId();
        $ativoAntes = $oabB->isAtivo();
        $this->limparIdentityMap();

        $this->logarComTenant($client, $gestorA, $tenantA);
        $client->request('POST', '/djen/oabs/' . $id . '/alternar', ['_token' => 'irrelevante']);
        self::assertResponseStatusCodeSame(404);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        if ($em->getFilters()->isEnabled('tenant')) {
            $em->getFilters()->disable('tenant');
        }
        $em->clear();
        $recarregada = $em->find(OabMonitorada::class, $id);
        self::assertNotNull($recarregada);
        self::assertSame($ativoAntes, $recarregada->isAtivo(), 'A não pode alterar o status da OAB de B');
    }
}
