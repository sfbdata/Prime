<?php

declare(strict_types=1);

namespace App\Tests\Tenant\Support;

use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Tenant\Entity\ConfiguracaoDeEdicao;
use App\Tenant\Service\PoderDeEdicaoSemPrazo;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Cenário comum dos testes da edição sem prazo (spec `edicao-sem-prazo-master-primario.md` §7): um escritório com
 * autor, terceiro (secundários), primário e Master; CSRF previsível (`TOKEN_<id>`) e a opção do primário.
 * Quem usa precisa de `$this->em` e do cliente com `disableReboot()`.
 */
trait CenarioDeEdicaoSemPrazo
{
    protected EntityManagerInterface $em;

    protected function novoEscritorio(string $nome = 'Escritório Sem Prazo'): Tenant
    {
        $tenant = (new Tenant())->setCidade('Maceió')->setEstado('AL');
        $tenant->setName($nome . ' ' . uniqid());
        $this->em->persist($tenant);
        $this->em->flush();

        return $tenant;
    }

    /** @param list<string> $papeis */
    protected function novaPessoa(Tenant $tenant, string $nome, bool $primario = false, array $papeis = ['ROLE_USER']): User
    {
        $user = (new User())->setEmail(uniqid('sp_') . '@test.com')->setFullName($nome)->setRoles($papeis)->setIsActive(true);
        $user->setPassword('x');
        $this->em->persist($user);
        $perfil = (new TenantRole())->setTenant($tenant)->setName(($primario ? 'Administrador do Escritório ' : 'Papel ') . uniqid())->setIsSystem($primario);
        $this->em->persist($perfil);
        $vinculo = (new UserTenant($user, $tenant))->setTenantRole($perfil);
        $this->em->persist($vinculo);
        $this->em->flush();

        return $user;
    }

    protected function desligarOpcaoDoPrimario(Tenant $tenant, User $primario): void
    {
        $configuracao = new ConfiguracaoDeEdicao($tenant);
        $configuracao->alterar(false, $primario, new \DateTimeImmutable());
        $this->em->persist($configuracao);
        $this->em->flush();
        static::getContainer()->get(PoderDeEdicaoSemPrazo::class)->esquecer();
    }

    /** As linhas da auditoria da edição sem prazo (`sp_editar` / `sp_excluir`) do escritório. */
    protected function auditoriaSemPrazo(Tenant $tenant): array
    {
        return $this->em->getRepository(AuditLog::class)->findBy(['tenantId' => $tenant->getId(), 'action' => ['sp_editar', 'sp_excluir']], ['id' => 'ASC']);
    }

    protected function instalarCsrfPrevisivel(): void
    {
        static::getContainer()->set('security.csrf.token_storage', new class () implements ClearableTokenStorageInterface {
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
        });
    }
}
