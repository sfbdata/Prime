<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Functional;

use App\Controller\Tenant\TenantSelecaoController;
use App\Entity\Auth\Invitation;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

#[CoversClass(TenantSelecaoController::class)]
final class TenantSelecaoControllerTest extends JusPrimeWebTestCase
{
    private function criarTenant(string $nome): Tenant
    {
        $em     = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName($nome . ' ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarUsuario(): User
    {
        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);
        $hasher    = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('selecao_' . uniqid() . '@test.com');
        $user->setFullName('Usuário Seleção');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function vincular(User $user, Tenant $tenant): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string { return 'TOKEN_' . $tokenId; }
            public function setToken(string $tokenId, string $token): void {}
            public function removeToken(string $tokenId): ?string { return null; }
            public function hasToken(string $tokenId): bool { return true; }
            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    #[TestDox('POST trocar para um escritório com vínculo redireciona ao expediente')]
    public function testTrocaComSucesso(): void
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant('Escritório A');
        $tenantB = $this->criarTenant('Escritório B');
        $user    = $this->criarUsuario();
        $this->vincular($user, $tenantA);
        $this->vincular($user, $tenantB);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenantA);
        $client->request('POST', '/escritorio/selecionar', [
            'tenant_id'    => $tenantB->getId(),
            '_csrf_token'  => 'TOKEN_tenant_selecionar',
        ]);

        self::assertResponseRedirects();
        self::assertStringContainsString('expediente', (string) $client->getResponse()->headers->get('Location'));
    }

    #[TestDox('POST trocar para escritório sem vínculo é negado e volta à seleção')]
    public function testTrocaSemVinculoNegada(): void
    {
        $client      = static::createClient();
        $tenantA     = $this->criarTenant('Escritório A');
        $tenantAlheio = $this->criarTenant('Escritório Alheio');
        $user        = $this->criarUsuario();
        $this->vincular($user, $tenantA);

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenantA);
        $client->request('POST', '/escritorio/selecionar', [
            'tenant_id'   => $tenantAlheio->getId(),
            '_csrf_token' => 'TOKEN_tenant_selecionar',
        ]);

        self::assertResponseRedirects('/escritorio/selecionar');
    }

    #[TestDox('GET estado vazio lista os convites de escritório pendentes')]
    public function testEstadoVazioListaConvites(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant('Escritório Convidado');
        $user   = $this->criarUsuario();

        $em      = static::getContainer()->get(EntityManagerInterface::class);
        $convite = new Invitation($user->getEmail(), 'tok_' . uniqid(), 'office', new \DateTimeImmutable('+5 days'));
        $convite->setTenant($tenant);
        $em->persist($convite);
        $em->flush();

        $client->loginUser($user);
        $this->marcarTermosAceitos($client);
        $client->request('GET', '/escritorio/selecionar');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'convite');
        self::assertSelectorTextContains('body', $tenant->getName());
    }

    #[TestDox('Trocar para um escritório EXCLUÍDO (inativo) é negado (RS06)')]
    public function testTrocaParaTenantExcluidoNegada(): void
    {
        $client  = static::createClient();
        $tenantA = $this->criarTenant('Ativo');
        $tenantB = $this->criarTenant('Excluído');
        $user    = $this->criarUsuario();
        $this->vincular($user, $tenantA);
        $this->vincular($user, $tenantB);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenantB->setIsActive(false);
        $em->flush();

        $this->instalarCsrfStorage();
        $this->logarComTenant($client, $user, $tenantA);
        $client->request('POST', '/escritorio/selecionar', [
            'tenant_id'   => $tenantB->getId(),
            '_csrf_token' => 'TOKEN_tenant_selecionar',
        ]);

        self::assertResponseRedirects('/escritorio/selecionar');
    }

    #[TestDox('Usuário cujo único escritório foi excluído cai no estado vazio (RS06 em findActiveByUser)')]
    public function testEstadoVazioQuandoUnicoTenantExcluido(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant('Excluído');
        $user   = $this->criarUsuario();
        $this->vincular($user, $tenant);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant->setIsActive(false);
        $em->flush();

        $client->loginUser($user);
        $this->marcarTermosAceitos($client);
        $client->request('GET', '/escritorio/selecionar');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Criar meu escritório');
    }

    #[TestDox('Convite de escritório EXCLUÍDO não aparece no estado vazio (I3)')]
    public function testConviteDeTenantInativoNaoAparece(): void
    {
        $client = static::createClient();
        $tenant = $this->criarTenant('Escritório Excluído');
        $user   = $this->criarUsuario();

        $em      = static::getContainer()->get(EntityManagerInterface::class);
        $tenant->setIsActive(false);
        $convite = new Invitation($user->getEmail(), 'tok_' . uniqid(), 'office', new \DateTimeImmutable('+5 days'));
        $convite->setTenant($tenant);
        $em->persist($convite);
        $em->flush();

        $client->loginUser($user);
        $this->marcarTermosAceitos($client);
        $client->request('GET', '/escritorio/selecionar');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('body', $tenant->getName());
    }
}
