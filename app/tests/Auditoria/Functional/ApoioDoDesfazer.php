<?php

declare(strict_types=1);

namespace App\Tests\Auditoria\Functional;

use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Apoio comum aos testes do desfazer da auditoria (`DesfazerExigeAGuardaDaTelaTest`, `DesfazerSegueOEstadoDaTelaTest`):
 * quem entra com quais permissões, a linha de auditoria e o POST do desfazer com um CSRF previsível.
 *
 * Quem usa declara `$client` (KernelBrowser), `$em` (EntityManagerInterface) e `$tenant` (Tenant).
 */
trait ApoioDoDesfazer
{
    /** O token de cada intenção é `TOKEN_<intenção>`: a página da auditoria e o desfazer usam o mesmo. */
    private function fingirOCsrf(): void
    {
        $storage = new class () implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }
            public function setToken(string $tokenId, string $token): void
            {
            }
            public function removeToken(string $tokenId): ?string
            {
                return null;
            }
            public function hasToken(string $tokenId): bool
            {
                return true;
            }
            public function clear(): void
            {
            }
        };
        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    /** @param list<string> $permissoes */
    private function entrarCom(array $permissoes): User
    {
        // Depois de um desfazer (que limpa o EntityManager), o escritório volta a ser gerenciado.
        if (!$this->em->contains($this->tenant)) {
            $this->tenant = $this->em->find(Tenant::class, $this->tenant->getId()) ?? $this->tenant;
        }
        $user = $this->pessoa('Auditora');

        $role = new TenantRole();
        $role->setTenant($this->tenant);
        $role->setName('Auditoria ' . uniqid());
        $role->setIsSystem(false);
        $this->em->persist($role);

        foreach ($permissoes as $codigo) {
            $ligacao = new TenantRolePermission();
            $ligacao->setTenantRole($role);
            $ligacao->setPermission($this->permissao($codigo));
            $this->em->persist($ligacao);
            $role->getTenantRolePermissions()->add($ligacao);
        }

        $vinculo = $this->em->getRepository(UserTenant::class)->findOneBy(['user' => $user, 'tenant' => $this->tenant]);
        self::assertInstanceOf(UserTenant::class, $vinculo);
        $vinculo->setTenantRole($role);
        $this->em->flush();

        $this->logarComTenant($this->client, $user, $this->tenant);

        return $user;
    }

    private function permissao(string $codigo): Permission
    {
        $perm = $this->em->getRepository(Permission::class)->findOneBy(['code' => $codigo]);
        if ($perm === null) {
            $perm = new Permission();
            $perm->setCode($codigo);
            $perm->setDescription($codigo);
            $perm->setGroup(explode('.', $codigo)[0]);
            $this->em->persist($perm);
        }

        return $perm;
    }

    private function pessoa(string $nome): User
    {
        $user = new User();
        $user->setEmail('guarda_tela_' . uniqid() . '@test.com');
        $user->setFullName($nome);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em->persist($user);
        $this->em->persist(new UserTenant($user, $this->tenant));
        $this->em->flush();

        return $user;
    }

    /** @param array<string, array{from: mixed, to: mixed}> $diff */
    private function criarLog(string $classe, int $entityId, array $diff): int
    {
        $log = (new AuditLog())
            ->setAction('update')
            ->setEntityClass($classe)
            ->setEntityId((string) $entityId)
            ->setChanges(['diff' => ['changes' => $diff], 'context' => []])
            ->setTenantId($this->tenant->getId());
        $this->em->persist($log);
        $this->em->flush();

        return (int) $log->getId();
    }

    private function desfazer(int $logId): void
    {
        $this->em->clear();
        $this->client->request('POST', "/auditoria/{$logId}/desfazer", ['_token' => 'TOKEN_audit_desfazer_' . $logId]);
    }
}
