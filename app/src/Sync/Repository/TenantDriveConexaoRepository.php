<?php

declare(strict_types=1);

namespace App\Sync\Repository;

use App\Entity\Tenant\Tenant;
use App\Sync\Entity\TenantDriveConexao;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TenantDriveConexao>
 */
// Não-final: permite substituição por mock nos testes de UseCase.
class TenantDriveConexaoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TenantDriveConexao::class);
    }

    public function salvar(TenantDriveConexao $conexao, bool $flush = false): void
    {
        $this->getEntityManager()->persist($conexao);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * A conexão do tenant (haja ou não). Tenant SEMPRE explícito — o TenantFilter não cobre
     * find() por PK e aqui buscamos pela relação, defesa em profundidade multi-tenant.
     */
    public function findDoTenant(Tenant $tenant): ?TenantDriveConexao
    {
        return $this->findOneBy(['tenant' => $tenant]);
    }

    /** A conexão do tenant apenas se estiver ativa/pronta; senão null (sync é no-op). */
    public function findAtivaDoTenant(Tenant $tenant): ?TenantDriveConexao
    {
        $conexao = $this->findOneBy(['tenant' => $tenant, 'ativo' => true]);

        return $conexao !== null && $conexao->estaPronta() ? $conexao : null;
    }

    /**
     * Todas as conexões ativas (todos os tenants) — usado pelo reconciliar multi-tenant no CLI,
     * onde o TenantFilter está desligado. Cada uma será processada com o client do seu tenant.
     *
     * @return list<TenantDriveConexao>
     */
    public function findTodasAtivas(): array
    {
        return $this->findBy(['ativo' => true]);
    }
}
