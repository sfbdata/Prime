<?php

declare(strict_types=1);

namespace App\Tests\Djen\Functional;

use App\Djen\Entity\OabMonitorada;
use App\Djen\Entity\PublicacaoDjen;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Fixtures manuais (via EntityManager) para os testes funcionais do DJEN — mesmo padrão do
 * ProcessoIsolamentoControllerTest. Papel isSystem(true) prova que quem fecha o vazamento
 * cross-tenant é o TenantFilter na camada de dados, não a permissão.
 */
trait CriaFixturesDjenTrait
{
    private function criarTenant(): Tenant
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tenant = new Tenant();
        $tenant->setName('Tenant DJEN ' . uniqid());
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }

    private function criarGestor(Tenant $tenant, string $email): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

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

    private function criarUsuarioComum(Tenant $tenant): User
    {
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('comum_' . uniqid() . '@test.com');
        $user->setFullName('Comum ' . uniqid());
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        // Papel NÃO-system e sem permissões → não tem modules.djen.view.
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Comum ' . uniqid());
        $role->setIsSystem(false);
        $em->persist($role);

        $userTenant = new UserTenant($user, $tenant);
        $userTenant->setTenantRole($role);
        $em->persist($userTenant);
        $em->flush();

        return $user;
    }

    private function criarOab(Tenant $tenant, string $numero, string $uf): OabMonitorada
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $oab = new OabMonitorada();
        $oab->setTenant($tenant);
        $oab->setNumero($numero);
        $oab->setUf($uf);
        $em->persist($oab);
        $em->flush();

        return $oab;
    }

    private function criarPublicacao(Tenant $tenant, string $djenId, string $numeroProcesso): PublicacaoDjen
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $pub = new PublicacaoDjen();
        $pub->setTenant($tenant);
        $pub->setDjenId($djenId);
        $pub->setNumeroProcesso($numeroProcesso);
        $pub->setSiglaTribunal('CJF');
        $pub->setTipoComunicacao('Intimação');
        $pub->setDataDisponibilizacao(new \DateTimeImmutable('2026-07-06'));
        $em->persist($pub);
        $em->flush();

        return $pub;
    }

    private function limparIdentityMap(): void
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
    }
}
