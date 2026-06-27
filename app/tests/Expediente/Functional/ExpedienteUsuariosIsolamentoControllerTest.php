<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Expediente\Controller\ExpedienteController;
use App\Expediente\Entity\Marcador;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * A1 — o dropdown de "responsável" do Expediente (acervo geral e painel por marcador) vazava TODOS
 * os usuários de TODOS os escritórios via `findBy(['isActive'=>true])` (User não é TenantAware).
 * Agora usa `findColaboradoresAtivosPorTenant`. Os dois endpoints são AJAX (XHR) e devolvem o
 * partial `pasta/_tabela.html.twig`, que renderiza o dropdown com o nome de cada responsável.
 */
#[CoversClass(ExpedienteController::class)]
final class ExpedienteUsuariosIsolamentoControllerTest extends JusPrimeWebTestCase
{
    private const NOME_COLAB_A = 'Fulano DoEscritorioA Unico';
    private const NOME_USER_B  = 'Beltrano DoEscritorioB Unico';

    #[TestDox('A1 leitura: o acervo geral não lista responsáveis de outro escritório')]
    public function testAcervoGeralNaoListaResponsaveisDeOutroTenant(): void
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $adminA  = $this->criarUsuario($tenantA, 'Admin Escritorio A', admin: true);
        $this->criarUsuario($tenantA, self::NOME_COLAB_A);
        $this->criarUsuario($tenantB, self::NOME_USER_B);

        $this->logarComTenant($client, $adminA, $tenantA);
        $client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString(self::NOME_COLAB_A, $body, 'o colaborador do próprio escritório deveria aparecer no filtro de responsável');
        self::assertStringNotContainsString(self::NOME_USER_B, $body, 'o filtro de responsável vazou um usuário de outro escritório');
    }

    #[TestDox('A1 leitura: o painel por marcador não lista responsáveis de outro escritório')]
    public function testPainelMarcadorNaoListaResponsaveisDeOutroTenant(): void
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $adminA  = $this->criarUsuario($tenantA, 'Admin Escritorio A', admin: true);
        $this->criarUsuario($tenantA, self::NOME_COLAB_A);
        $this->criarUsuario($tenantB, self::NOME_USER_B);
        $marcador = $this->criarMarcador($tenantA, $adminA);

        $this->logarComTenant($client, $adminA, $tenantA);
        $client->xmlHttpRequest('GET', "/expediente/marcador/{$marcador->getId()}/pastas");

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString(self::NOME_COLAB_A, $body, 'o colaborador do próprio escritório deveria aparecer no filtro de responsável');
        self::assertStringNotContainsString(self::NOME_USER_B, $body, 'o filtro de responsável vazou um usuário de outro escritório');
    }

    // ----------------------------------------------------------------- helpers

    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant EXP ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarUsuario(Tenant $tenant, string $nome, bool $admin = false): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('u_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $userTenant = new UserTenant($user, $tenant);
        if ($admin) {
            $role = new TenantRole();
            $role->setTenant($tenant);
            $role->setName('Administrador ' . uniqid());
            $role->setIsSystem(true);
            $em->persist($role);
            $userTenant->setTenantRole($role);
        }
        $em->persist($userTenant);
        $em->flush();

        return $user;
    }

    private function criarMarcador(Tenant $tenant, User $criadoPor): Marcador
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $marcador = new Marcador('Marcador A ' . uniqid(), $tenant, $criadoPor);
        $em->persist($marcador);
        $em->flush();

        return $marcador;
    }
}
