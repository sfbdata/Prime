<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Ponto\Entity\JustificativaPonto;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Cenário dos testes do cartão de abas do Ponto (E3): escritórios, pessoas com o papel certo (primário, gestor
 * pelo perfil ou colaborador), justificativas do mês e o CSRF previsível (`TOKEN_<id>`).
 */
trait CenarioDeJustificativaTrait
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** O banco como está, sem o filtro do escritório da sessão e sem o que o EntityManager guardou. */
    private function emSemFiltro(): EntityManagerInterface
    {
        $em = $this->em();
        if ($em->getFilters()->isEnabled('tenant')) {
            $em->getFilters()->disable('tenant');
        }
        $em->clear();

        return $em;
    }

    private function escritorio(string $nome): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName($nome . ' ' . uniqid());
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    /** @param list<string> $papeis */
    private function pessoa(string $prefixo, array $papeis = ['ROLE_USER']): User
    {
        $user = new User();
        $user->setEmail($prefixo . '_' . uniqid() . '@test.com');
        $user->setFullName('Pessoa ' . $prefixo);
        $user->setRoles($papeis);
        $user->setIsActive(true);
        $user->setPassword('x');
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    /** Primário: o perfil de sistema do escritório. */
    private function vincularPrimario(User $user, Tenant $tenant): void
    {
        $this->vincular($user, $tenant, [], true);
    }

    /** Colaborador: só o módulo do ponto. */
    private function vincularColaborador(User $user, Tenant $tenant): void
    {
        $this->vincular($user, $tenant, ['modules.ponto.view'], false);
    }

    /** Gestor pelo perfil: analisa justificativas (admin.users.manage), mas não é primário. */
    private function vincularGestor(User $user, Tenant $tenant): void
    {
        $this->vincular($user, $tenant, ['modules.ponto.view', 'admin.users.manage'], false);
    }

    /** @param list<string> $codigos */
    private function vincular(User $user, Tenant $tenant, array $codigos, bool $sistema): void
    {
        $em    = $this->em();
        $papel = new TenantRole();
        $papel->setTenant($tenant);
        $papel->setName('Papel ' . uniqid());
        $papel->setIsSystem($sistema);
        $em->persist($papel);

        foreach ($codigos as $codigo) {
            $ligacao = new TenantRolePermission();
            $ligacao->setTenantRole($papel);
            $ligacao->setPermission($this->permissao($codigo));
            $em->persist($ligacao);
            $papel->getTenantRolePermissions()->add($ligacao);
        }

        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($papel);
        $em->persist($vinculo);
        $em->flush();
    }

    private function permissao(string $codigo): Permission
    {
        $permissao = $this->em()->getRepository(Permission::class)->findOneBy(['code' => $codigo]);
        if ($permissao === null) {
            $permissao = new Permission();
            $permissao->setCode($codigo);
            $permissao->setDescription($codigo);
            $permissao->setGroup(explode('.', $codigo)[0]);
            $this->em()->persist($permissao);
        }

        return $permissao;
    }

    /** @param array<string, mixed> $campos tipo, tipoRegistroEsquecido, horaRegistroEsquecido, observacaoAnalise, dataAnalise, anexoPath, batchId */
    private function justificativa(User $user, Tenant $tenant, \DateTimeInterface $dia, string $status, array $campos = []): JustificativaPonto
    {
        $j = new JustificativaPonto();
        $j->setUser($user);
        $j->setTenant($tenant);
        $j->setData(new \DateTime($dia->format('Y-m-d')));
        $j->setStatus($status);
        $j->setTipo($campos['tipo'] ?? 'atestado_medico');
        $j->setTipoRegistroEsquecido($campos['tipoRegistroEsquecido'] ?? null);
        $j->setHoraRegistroEsquecido(isset($campos['horaRegistroEsquecido']) ? new \DateTime('1970-01-01 ' . $campos['horaRegistroEsquecido'] . ':00') : null);
        $j->setObservacaoAnalise($campos['observacaoAnalise'] ?? null);
        $j->setDataAnalise(isset($campos['dataAnalise']) ? new \DateTime($campos['dataAnalise']) : null);
        $j->setAnexoPath($campos['anexoPath'] ?? null);
        $j->setBatchId($campos['batchId'] ?? bin2hex(random_bytes(8)));
        $this->em()->persist($j);
        $this->em()->flush();

        return $j;
    }

    /** O token de cada id vira `TOKEN_<id>`: o teste manda o valor sem passar pela tela. */
    private function instalarCsrfPrevisivel(): void
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

    private function statusNoBanco(JustificativaPonto|int $justificativa): ?string
    {
        $id = $justificativa instanceof JustificativaPonto ? $justificativa->getId() : $justificativa;

        return $this->emSemFiltro()->getConnection()->fetchOne('SELECT status FROM justificativa_ponto WHERE id = ?', [$id]) ?: null;
    }
}
