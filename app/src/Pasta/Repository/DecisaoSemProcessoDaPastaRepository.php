<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\DecisaoSemProcessoDaPasta;
use App\Pasta\Entity\Pasta;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DecisaoSemProcessoDaPasta>
 */
class DecisaoSemProcessoDaPastaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DecisaoSemProcessoDaPasta::class);
    }

    public function salvar(DecisaoSemProcessoDaPasta $decisao, bool $flush = false): void
    {
        $this->getEntityManager()->persist($decisao);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function daPasta(Pasta $pasta, Tenant $tenant): ?DecisaoSemProcessoDaPasta
    {
        return $this->findOneBy(['pasta' => $pasta, 'tenant' => $tenant]);
    }

    /**
     * Todas as decisões do escritório, no formato do analisador: o aprendizado (L4515) olha as do
     * escritório inteiro, e a "Decisão anterior" de cada linha sai daqui.
     *
     * @return array<int, array{decisao: string, por: string, termos: list<string>}> pastaId => decisão
     */
    public function doEscritorio(Tenant $tenant): array
    {
        $linhas = $this->createQueryBuilder('d')
            ->select('IDENTITY(d.pasta) AS pasta_id', 'd.decisao', 'd.termos', 'u.fullName', 'u.email')
            ->leftJoin('d.por', 'u')
            ->andWhere('d.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('d.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $porPasta = [];
        foreach ($linhas as $l) {
            $porPasta[(int) $l['pasta_id']] = [
                'decisao' => (string) $l['decisao'],
                'por'     => (string) ($l['fullName'] ?? $l['email'] ?? ''),
                'termos'  => array_values(array_map('strval', (array) $l['termos'])),
            ];
        }

        return $porPasta;
    }
}
