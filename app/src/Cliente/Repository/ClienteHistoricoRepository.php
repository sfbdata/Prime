<?php

declare(strict_types=1);

namespace App\Cliente\Repository;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClienteHistorico;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Linhas da aba Histórico do cadastro. Toda leitura é pelo escritório E pelo cliente, com o escritório explícito
 * (não só pelo TenantFilter).
 *
 * @extends ServiceEntityRepository<ClienteHistorico>
 */
class ClienteHistoricoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ClienteHistorico::class);
    }

    /** Só persiste: quem chama dá o flush junto da própria mudança (mesma transação). */
    public function registrar(ClienteHistorico $linha): void
    {
        $this->getEntityManager()->persist($linha);
    }

    /**
     * As mais novas primeiro (desenho: lista invertida). Empate no mesmo segundo desempata pela ordem de gravação.
     *
     * @return list<ClienteHistorico>
     */
    public function ultimosDoCliente(Cliente $cliente, Tenant $tenant, int $limite = 40): array
    {
        /** @var list<ClienteHistorico> $linhas */
        $linhas = $this->createQueryBuilder('h')
            ->andWhere('h.tenant = :tenant')
            ->andWhere('h.cliente = :cliente')
            ->setParameter('tenant', $tenant)
            ->setParameter('cliente', $cliente)
            ->orderBy('h.em', 'DESC')
            ->addOrderBy('h.id', 'DESC')
            ->setMaxResults(max(1, $limite))
            ->getQuery()
            ->getResult();

        return $linhas;
    }
}
