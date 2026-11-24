<?php

declare(strict_types=1);

namespace App\Master\Repository;

use App\Master\Entity\ConvenioVinculo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Os vínculos dos escritórios com os convênios (`convenio_vinculo`, spec docs/specs/convenios.md, §3.2).
 *
 * {@see ConvenioVinculo} NÃO é TenantAware (D12): toda consulta daqui filtra por tenant explícito, escrito à mão, e
 * este é o único lugar que a consulta (`ConvenioVinculoSoNoRepositorioArquiteturaTest`). Em lote, para o painel do
 * Master ler todas as contas numa consulta só, como `BonusDeEspacoRepository::vigentesDosTenants`.
 *
 * @extends ServiceEntityRepository<ConvenioVinculo>
 */
class ConvenioVinculoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConvenioVinculo::class);
    }

    /**
     * Os vínculos abertos (`fim` nulo) dos escritórios, com o convênio já carregado.
     *
     * @param list<int> $tenantIds
     *
     * @return array<int, list<ConvenioVinculo>> tenantId => vínculos abertos
     */
    public function abertosDosTenants(array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }
        /** @var list<ConvenioVinculo> $lista */
        $lista = $this->createQueryBuilder('v')
            ->addSelect('c')
            ->join('v.convenio', 'c')
            ->andWhere('IDENTITY(v.tenant) IN (:tenants)')
            ->andWhere('v.fim IS NULL')
            ->setParameter('tenants', $tenantIds)
            ->orderBy('v.id', 'ASC')
            ->getQuery()
            ->getResult();

        $porTenant = [];
        foreach ($lista as $vinculo) {
            $porTenant[(int) $vinculo->getTenant()->getId()][] = $vinculo;
        }

        return $porTenant;
    }

    /**
     * Os escritórios, entre os pedidos, que já têm algum vínculo gravado, aberto ou fechado. Quem não tem nenhum ainda
     * não passou pela sincronização (PR 3): para ele o convênio OAB vale implícito (decisão de 24/11, §3.4).
     *
     * @param list<int> $tenantIds
     *
     * @return list<int>
     */
    public function tenantsComVinculo(array $tenantIds): array
    {
        if ($tenantIds === []) {
            return [];
        }
        $ids = $this->createQueryBuilder('v')
            ->select('DISTINCT IDENTITY(v.tenant) AS tenant')
            ->andWhere('IDENTITY(v.tenant) IN (:tenants)')
            ->setParameter('tenants', $tenantIds)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map('intval', $ids);
    }

    public function salvar(ConvenioVinculo $vinculo, bool $flush = false): void
    {
        $this->getEntityManager()->persist($vinculo);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
