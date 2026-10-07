<?php

namespace App\Pasta\Repository;

use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaMensagem;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PastaMensagem>
 */
class PastaMensagemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PastaMensagem::class);
    }

    /**
     * @return PastaMensagem[]
     */
    public function findByPasta(Pasta $pasta, Tenant $tenant, int $limit = 150): array
    {
        // Autor e original respondida vêm na MESMA consulta: a linha do tempo lê o nome de cada
        // autor, e sem o join cada autor distinto custava um SELECT a mais (N+1). Só relações
        // para-um — o `setMaxResults` continua valendo por mensagem.
        return $this->createQueryBuilder('m')
            ->leftJoin('m.autor', 'autor')->addSelect('autor')
            ->leftJoin('m.respostaA', 'raiz')->addSelect('raiz')
            ->leftJoin('raiz.autor', 'raizAutor')->addSelect('raizAutor')
            ->andWhere('m.pasta = :pasta')
            ->andWhere('m.tenant = :tenant')
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->orderBy('m.criadaEm', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
