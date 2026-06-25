<?php

declare(strict_types=1);

namespace App\Tests\Processo\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Processo\Controller\ProcessoController;
use App\Processo\Entity\Processo;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Isolamento multi-tenant do ProcessoController via HTTP. Gestores isSystem (que bypassam o
 * PermissionChecker) provam que é o TenantFilter na camada de dados — não a permissão — que
 * fecha o vazamento. em->clear() após os fixtures força o find()/ParamConverter a executar SQL
 * real (em produção a identity map começa vazia por request).
 */
#[CoversClass(ProcessoController::class)]
final class ProcessoIsolamentoControllerTest extends JusPrimeWebTestCase
{
    private int $seq = 0;

    #[TestDox('Dono acessa o próprio processo; gestor de outro tenant recebe 404 (show)')]
    public function testShowIsolaPorTenant(): void
    {
        $client = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $gestorA = $this->criarGestor($tenantA, 'gestorA_' . uniqid() . '@test.com');
        $gestorB = $this->criarGestor($tenantB, 'gestorB_' . uniqid() . '@test.com');
        $procB = $this->criarProcesso($tenantB);
        $id = (int) $procB->getId();
        $this->limparIdentityMap();

        $this->logarComTenant($client, $gestorB, $tenantB);
        $client->request('GET', "/processos/{$id}");
        self::assertResponseIsSuccessful();

        $this->logarComTenant($client, $gestorA, $tenantA);
        $client->request('GET', "/processos/{$id}");
        self::assertResponseStatusCodeSame(404, 'show não pode revelar processo de outro tenant');
    }

    #[TestDox('Editar/deletar processo de outro tenant retorna 404')]
    public function testEditarEDeletarIsolamPorTenant(): void
    {
        $client = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $gestorA = $this->criarGestor($tenantA, 'gestorA_' . uniqid() . '@test.com');
        $procB = $this->criarProcesso($tenantB);
        $id = (int) $procB->getId();
        $this->limparIdentityMap();

        $this->logarComTenant($client, $gestorA, $tenantA);

        $client->request('GET', "/processos/{$id}/editar");
        self::assertResponseStatusCodeSame(404, 'editar não pode revelar processo de outro tenant');

        $client->request('POST', "/processos/{$id}/deletar");
        self::assertResponseStatusCodeSame(404, 'deletar não pode tocar processo de outro tenant');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        if ($em->getFilters()->isEnabled('tenant')) {
            $em->getFilters()->disable('tenant');
        }
        $em->clear();
        self::assertNotNull($em->find(Processo::class, $id));
    }

    #[TestDox('A listagem (index) não mostra processos de outro tenant')]
    public function testIndexNaoVazaOutroTenant(): void
    {
        $client = static::createClient();
        $tenantA = $this->criarTenant();
        $tenantB = $this->criarTenant();
        $gestorA = $this->criarGestor($tenantA, 'gestorA_' . uniqid() . '@test.com');
        $procA = $this->criarProcesso($tenantA);
        $procB = $this->criarProcesso($tenantB);
        $numeroA = $procA->getNumeroProcesso();
        $numeroB = $procB->getNumeroProcesso();
        $this->limparIdentityMap();

        $this->logarComTenant($client, $gestorA, $tenantA);
        $client->request('GET', '/processos/');

        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        self::assertStringContainsString($numeroA, $body, 'index deveria listar o processo do próprio tenant');
        self::assertStringNotContainsString($numeroB, $body, 'index vazou processo de outro tenant');
    }

    // ----------------------------------------------------------------- helpers

    private function limparIdentityMap(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }

    private function criarTenant(): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant PROC ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarGestor(Tenant $tenant, string $email): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail($email);
        $user->setFullName('Gestor ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Gestor ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        return $user;
    }

    private function criarProcesso(Tenant $tenant): Processo
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $n  = ++$this->seq;

        $processo = new Processo();
        $processo->setNumeroProcesso('PROC' . $n . 'Z' . substr(uniqid(), -6));
        $processo->setSiglaTribunal('TRT2');
        $processo->setOrgaoJulgador('1a Vara');
        $processo->setClasseProcessual('Reclamação');
        $processo->setAssuntoProcessual('Rescisão');
        $processo->setSituacaoProcesso('Em Andamento');
        $processo->setInstancia('1a Instância');
        $processo->setTenant($tenant);
        $em->persist($processo);
        $em->flush();

        return $processo;
    }
}
