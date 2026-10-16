<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaReu;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** Réus cadastrados nas pastas (E12h). Tenant sempre explícito no critério. */
class PastaReuRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PastaReu::class);
    }

    /** @return list<PastaReu> na ordem (o primeiro é o principal) */
    public function daPasta(Pasta $pasta, Tenant $tenant): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.pasta = :pasta')
            ->andWhere('r.tenant = :tenant')
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->orderBy('r.ordem', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Nomes por pasta, na ordem, para a coluna Réu da Lista e o CSV.
     *
     * @param list<int> $pastaIds
     *
     * @return array<int, list<string>>
     */
    public function nomesPorPasta(array $pastaIds, Tenant $tenant): array
    {
        if ($pastaIds === []) {
            return [];
        }
        $linhas = $this->createQueryBuilder('r')
            ->select('IDENTITY(r.pasta) AS pasta_id', 'r.nome')
            ->andWhere('r.tenant = :tenant')
            ->andWhere('r.pasta IN (:ids)')
            ->setParameter('tenant', $tenant)
            ->setParameter('ids', $pastaIds)
            ->orderBy('r.ordem', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($linhas as $l) {
            $out[(int) $l['pasta_id']][] = (string) $l['nome'];
        }

        return $out;
    }
}
