<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\PastaChecklistModelo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PastaChecklistModelo>
 */
class PastaChecklistModeloRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PastaChecklistModelo::class);
    }

    /**
     * Modelos do escritório, em ordem alfabética.
     *
     * Sem paginação de propósito: isto é configuração do escritório (uma lista curta que a
     * equipe mantém à mão), não dado de negócio que cresce sozinho. Um teto silencioso aqui
     * esconderia modelo salvo, que é pior do que a lista ficar longa.
     *
     * @return PastaChecklistModelo[]
     */
    public function listarDoTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('m.nome', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Guarda de posse: modelo de outro escritório volta null, e o controller responde 404.
     * Não confie só no TenantFilter global — ele não está ligado em CLI nem em teste.
     */
    public function buscarDoTenant(int $id, Tenant $tenant): ?PastaChecklistModelo
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.id = :id')
            ->andWhere('m.tenant = :tenant')
            ->setParameter('id', $id)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** O nome já vem normalizado em maiúsculas pela entidade; a busca normaliza igual. */
    public function buscarPorNome(string $nome, Tenant $tenant): ?PastaChecklistModelo
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.nome = :nome')
            ->andWhere('m.tenant = :tenant')
            ->setParameter('nome', mb_strtoupper(trim($nome)))
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function salvar(PastaChecklistModelo $modelo, bool $flush = false): void
    {
        $this->getEntityManager()->persist($modelo);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(PastaChecklistModelo $modelo, bool $flush = false): void
    {
        $this->getEntityManager()->remove($modelo);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
