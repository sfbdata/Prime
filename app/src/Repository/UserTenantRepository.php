<?php
declare(strict_types=1);

namespace App\Repository;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserTenant>
 */
class UserTenantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserTenant::class);
    }

    /**
     * Vínculos ativos do usuário cujo ESCRITÓRIO também está ativo. Um tenant
     * excluído (soft delete, isActive=false) some do switcher, da seleção e do
     * roteamento de login mesmo que o vínculo em si continue ativo (RS06).
     *
     * @return UserTenant[]
     */
    public function findActiveByUser(User $user): array
    {
        return $this->createQueryBuilder('ut')
            ->join('ut.tenant', 't')
            ->andWhere('ut.user = :user')
            ->andWhere('ut.isActive = true')
            ->andWhere('t.isActive = true')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();
    }

    /**
     * Contas FORA DO AR (isActive=false: suspensas ou em quarentena de exclusão) com que o usuário tem
     * vínculo ativo. Base dos avisos da tela de seleção (spec perfis-1.2-contas C9 e C9b).
     *
     * @return list<array{nome: string, excluida: bool}>
     */
    public function contasForaDoArDoUsuario(User $user): array
    {
        $linhas = $this->createQueryBuilder('ut')
            ->select('t.name AS nome', 't.excluidoEm AS excluidoEm')
            ->join('ut.tenant', 't')
            ->andWhere('ut.user = :user')
            ->andWhere('ut.isActive = true')
            ->andWhere('t.isActive = false')
            ->setParameter('user', $user)
            ->orderBy('t.name')
            ->getQuery()
            ->getArrayResult();

        return array_values(array_map(
            static fn (array $l): array => ['nome' => (string) $l['nome'], 'excluida' => $l['excluidoEm'] !== null],
            $linhas,
        ));
    }

    public function existeVinculoAtivo(User $user, Tenant $tenant): bool
    {
        return $this->createQueryBuilder('ut')
            ->select('COUNT(ut.id)')
            ->andWhere('ut.user = :user')
            ->andWhere('ut.tenant = :tenant')
            ->andWhere('ut.isActive = true')
            ->setParameter('user', $user)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    public function findAtivoPorUserETenant(User $user, Tenant $tenant): ?UserTenant
    {
        return $this->findOneBy(['user' => $user, 'tenant' => $tenant, 'isActive' => true]);
    }

    public function findPorUserETenant(User $user, Tenant $tenant): ?UserTenant
    {
        return $this->findOneBy(['user' => $user, 'tenant' => $tenant]);
    }

    /**
     * Conta quantos administradores (vínculo ativo com TenantRole de
     * sistema) o escritório possui. Usado para impedir que o último
     * admin saia e deixe o escritório órfão.
     */
    public function contarAdminsAtivos(Tenant $tenant): int
    {
        // Conta só quem consegue agir: vínculo ativo E conta ativa (RegraDosPrimarios::ehPrimario).
        return (int) $this->createQueryBuilder('ut')
            ->select('COUNT(ut.id)')
            ->join('ut.tenantRole', 'r')
            ->join('ut.user', 'u')
            ->andWhere('ut.tenant = :tenant')
            ->andWhere('ut.isActive = true')
            ->andWhere('u.isActive = true')
            ->andWhere('r.isSystem = true')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Administrador ativo de vínculo mais antigo, ignorando quem está saindo.
     * Herda os quadros de Kanban quando não há substituto e a remoção veio da
     * saída por conta própria (não existe executor admin nesse caminho).
     */
    public function findAdminAtivoMaisAntigo(Tenant $tenant, User $exceto): ?UserTenant
    {
        return $this->createQueryBuilder('ut')
            ->join('ut.tenantRole', 'r')
            ->andWhere('ut.tenant = :tenant')
            ->andWhere('ut.isActive = true')
            ->andWhere('r.isSystem = true')
            ->andWhere('ut.user != :exceto')
            ->setParameter('tenant', $tenant)
            ->setParameter('exceto', $exceto)
            ->orderBy('ut.createdAt', 'ASC')
            ->addOrderBy('ut.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Vínculos ativos do escritório, com usuário e perfil já carregados, em ordem de nome.
     * Base da tela de edição do perfil primário (primários e secundários promovíveis).
     *
     * @return UserTenant[]
     */
    public function findAtivosComPerfilPorTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('ut')
            ->addSelect('u', 'r')
            ->join('ut.user', 'u')
            ->leftJoin('ut.tenantRole', 'r')
            ->andWhere('ut.tenant = :tenant')
            ->andWhere('ut.isActive = true')
            ->setParameter('tenant', $tenant)
            ->orderBy('u.fullName', 'ASC')
            ->addOrderBy('u.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Vínculos ativos de contas ativas do escritório, com usuário, perfil, cargo e lotação já carregados
     * (uma consulta, sem N+1), em ordem de nome. Base da lista "Ocultar aba" da pasta (spec
     * pasta-ocultar-aba §2.4): a "função · setor" de cada pessoa é cargo · lotação.
     *
     * @return UserTenant[]
     */
    public function findAtivosComCargoELotacaoPorTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('ut')
            ->addSelect('u', 'r', 'c', 'l')
            ->join('ut.user', 'u')
            ->leftJoin('ut.tenantRole', 'r')
            ->leftJoin('ut.cargo', 'c')
            ->leftJoin('ut.lotacao', 'l')
            ->andWhere('ut.tenant = :tenant')
            ->andWhere('ut.isActive = true')
            ->andWhere('u.isActive = true')
            ->setParameter('tenant', $tenant)
            ->orderBy('u.fullName', 'ASC')
            ->addOrderBy('u.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Vínculos ativos por perfil no escritório: [id do perfil => quantidade].
     *
     * @return array<int, int>
     */
    public function contarAtivosPorPerfil(Tenant $tenant): array
    {
        $linhas = $this->createQueryBuilder('ut')
            ->select('IDENTITY(ut.tenantRole) AS perfil', 'COUNT(ut.id) AS total')
            ->andWhere('ut.tenant = :tenant')
            ->andWhere('ut.isActive = true')
            ->andWhere('ut.tenantRole IS NOT NULL')
            ->setParameter('tenant', $tenant)
            ->groupBy('ut.tenantRole')
            ->getQuery()
            ->getArrayResult();

        $porPerfil = [];
        foreach ($linhas as $linha) {
            $porPerfil[(int) $linha['perfil']] = (int) $linha['total'];
        }

        return $porPerfil;
    }

    /**
     * Todos os vínculos (ativos e inativos) por perfil no escritório: [id do perfil => quantidade].
     * É o que a exclusão de perfil solta (TenantRoleController::delete).
     *
     * @return array<int, int>
     */
    public function contarVinculosPorPerfil(Tenant $tenant): array
    {
        $linhas = $this->createQueryBuilder('ut')
            ->select('IDENTITY(ut.tenantRole) AS perfil', 'COUNT(ut.id) AS total')
            ->andWhere('ut.tenant = :tenant')
            ->andWhere('ut.tenantRole IS NOT NULL')
            ->setParameter('tenant', $tenant)
            ->groupBy('ut.tenantRole')
            ->getQuery()
            ->getArrayResult();

        $porPerfil = [];
        foreach ($linhas as $linha) {
            $porPerfil[(int) $linha['perfil']] = (int) $linha['total'];
        }

        return $porPerfil;
    }

    /**
     * Usuários administradores ativos do escritório (perfil de sistema). Destino do "Pedir acesso" de uma
     * pasta trancada (E12).
     *
     * @return User[]
     */
    public function findUsuariosAdminsAtivos(Tenant $tenant): array
    {
        $vinculos = $this->createQueryBuilder('ut')
            ->join('ut.tenantRole', 'r')
            ->andWhere('ut.tenant = :tenant')
            ->andWhere('ut.isActive = true')
            ->andWhere('r.isSystem = true')
            ->setParameter('tenant', $tenant)
            ->orderBy('ut.id', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(static fn (UserTenant $ut): User => $ut->getUser(), $vinculos);
    }
}
