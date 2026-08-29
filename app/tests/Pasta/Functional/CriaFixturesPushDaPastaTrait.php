<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Djen\Entity\PublicacaoDjen;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Pasta\Entity\Pasta;
use App\Processo\Entity\Processo;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Fixtures da aba Push Processual da pasta: escritório, usuário, pasta, processo vinculado e
 * publicação — com ou sem a FK de processo, que é a diferença que estes testes precisam controlar.
 */
trait CriaFixturesPushDaPastaTrait
{

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function criarTenant(): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('Tenant Push ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    /** @return array{User, Tenant} */
    private function criarAdmin(): array
    {
        $tenant = $this->criarTenant();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = new User();
        $user->setEmail('push_admin_' . uniqid() . '@test.com');
        $user->setFullName('Admin Push');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $this->em()->persist($user);
        $this->em()->persist(new UserTenant($user, $tenant));
        $this->em()->flush();

        return [$user, $tenant];
    }

    /** Papel comum com `resources.pasta.view` e SEM `modules.djen.view`. */
    private function criarUsuarioSemPermissaoDoModulo(Tenant $tenant): User
    {
        $em     = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $perm = $em->getRepository(Permission::class)->findOneBy(['code' => 'resources.pasta.view']);
        if ($perm === null) {
            $perm = new Permission();
            $perm->setCode('resources.pasta.view');
            $perm->setDescription('Visualizar pasta específica');
            $perm->setGroup('resources');
            $em->persist($perm);
            $em->flush();
        }

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Sem Push ' . uniqid());
        $role->setIsSystem(false);
        $em->persist($role);

        $vinculo = new TenantRolePermission();
        $vinculo->setTenantRole($role);
        $vinculo->setPermission($perm);
        $em->persist($vinculo);
        $role->getTenantRolePermissions()->add($vinculo);

        $user = new User();
        $user->setEmail('push_sem_perm_' . uniqid() . '@test.com');
        $user->setFullName('Sem permissão do módulo');
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

    /** Papel comum SEM permissão nenhuma — nem a de ver pasta. */
    private function criarUsuarioSemNenhumaPermissao(Tenant $tenant): User
    {
        $em     = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Sem nada ' . uniqid());
        $role->setIsSystem(false);
        $em->persist($role);

        $user = new User();
        $user->setEmail('push_sem_nada_' . uniqid() . '@test.com');
        $user->setFullName('Sem permissão nenhuma');
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

    private function criarPasta(Tenant $tenant): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('PUSH-' . uniqid());
        $pasta->setTenant($tenant);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function criarProcesso(Tenant $tenant, string $numero): Processo
    {
        $processo = new Processo();
        $processo->setTenant($tenant);
        $processo->setNumeroProcesso($numero);
        $processo->setClasseProcessual('Procedimento Comum');
        $this->em()->persist($processo);
        $this->em()->flush();

        return $processo;
    }

    private function vincular(Pasta $pasta, Processo $processo): void
    {
        $pasta->vincularProcesso($processo);
        $this->em()->flush();
    }

    private function criarPublicacao(
        Tenant $tenant,
        string $djenId,
        string $numero,
        string $data,
        ?Processo $processo = null,
    ): PublicacaoDjen {
        $pub = new PublicacaoDjen();
        $pub->setTenant($tenant);
        $pub->setDjenId($djenId);
        $pub->setNumeroProcesso($numero);
        $pub->setNumeroProcessoComMascara('0701134-57.2025.8.07.0007');
        $pub->setSiglaTribunal('TJDFT');
        $pub->setTipoComunicacao('Intimação');
        $pub->setNomeOrgao('1ª Vara Cível de Brasília');
        $pub->setDataDisponibilizacao(new \DateTimeImmutable($data));
        $pub->setTexto('Teor da publicação ' . $djenId);
        if ($processo !== null) {
            $pub->setProcesso($processo);
        }
        $this->em()->persist($pub);
        $this->em()->flush();

        return $pub;
    }
}
