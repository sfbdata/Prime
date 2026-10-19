<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Permission\ResourceAccess;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Pasta\Entity\Pasta;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Fixtures da Busca rápida (Ctrl + K): escritório, pessoa com papel de sistema ou com permissões exatas,
 * pasta e acesso próprio a uma pasta.
 */
abstract class BuscaRapidaWebTestCase extends JusPrimeWebTestCase
{
    private int $seq = 0;

    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Busca Rapida ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    /** Administrador do escritório: papel de sistema, vê tudo e abre pasta trancada. */
    protected function criarAdministrador(Tenant $tenant): User
    {
        $role = $this->criarPapel($tenant, true);

        return $this->criarUsuario($tenant, $role);
    }

    /**
     * Papel comum (sem bypass) com exatamente estas permissões.
     *
     * @param list<string> $codigos
     */
    protected function criarUsuarioComPermissoes(Tenant $tenant, array $codigos): User
    {
        $em   = $this->em();
        $role = $this->criarPapel($tenant, false);
        foreach ($codigos as $codigo) {
            $perm = $em->getRepository(Permission::class)->findOneBy(['code' => $codigo]);
            if ($perm === null) {
                $perm = new Permission();
                $perm->setCode($codigo);
                $perm->setDescription($codigo);
                $perm->setGroup(explode('.', $codigo)[0]);
                $em->persist($perm);
            }

            $vinculo = new TenantRolePermission();
            $vinculo->setTenantRole($role);
            $vinculo->setPermission($perm);
            $em->persist($vinculo);
            $role->getTenantRolePermissions()->add($vinculo);
        }

        return $this->criarUsuario($tenant, $role);
    }

    protected function criarPasta(Tenant $tenant, ?string $nomeAcao = null): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('BRF' . (++$this->seq) . 'X' . strtoupper(substr(uniqid(), -7)));
        $pasta->setTenant($tenant);
        $pasta->setNomeAcao($nomeAcao);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    protected function darAcessoProprio(User $user, Tenant $tenant, Pasta $pasta): void
    {
        $acesso = (new ResourceAccess())
            ->setUser($user)
            ->setTenant($tenant)
            ->setResourceType('pasta')
            ->setResourceId((int) $pasta->getId())
            ->setCanView(true);
        $this->em()->persist($acesso);
        $this->em()->flush();
    }

    private function criarPapel(Tenant $tenant, bool $sistema): TenantRole
    {
        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Papel Busca Rapida ' . uniqid());
        $role->setIsSystem($sistema);
        $this->em()->persist($role);

        return $role;
    }

    private function criarUsuario(Tenant $tenant, TenantRole $role): User
    {
        $em     = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('busca_rapida_' . uniqid() . '@test.com');
        $user->setFullName('Pessoa Busca Rapida');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $em->persist($ut);
        $em->flush();

        return $user;
    }
}
