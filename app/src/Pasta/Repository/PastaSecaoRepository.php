<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaSecao;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PastaSecao>
 */
class PastaSecaoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PastaSecao::class);
    }

    /** @return PastaSecao[] */
    public function findByPasta(Pasta $pasta, Tenant $tenant): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.pasta = :pasta')
            ->andWhere('s.tenant = :tenant')
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->orderBy('s.ordem', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** Próxima ordem entre as IRMÃS de $pai (ou entre as seções da raiz, se $pai for null). */
    public function proximaOrdem(Pasta $pasta, Tenant $tenant, ?PastaSecao $pai = null): int
    {
        $qb = $this->createQueryBuilder('s')
            ->select('MAX(s.ordem)')
            ->andWhere('s.pasta = :pasta')
            ->andWhere('s.tenant = :tenant')
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant);

        if ($pai === null) {
            $qb->andWhere('s.pai IS NULL');
        } else {
            $qb->andWhere('s.pai = :pai')->setParameter('pai', $pai);
        }

        $max = $qb->getQuery()->getSingleScalarResult();

        return ($max === null ? 0 : (int) $max) + 1;
    }

    /**
     * Conta o que a exclusão de $secao levaria junto. As duas contagens têm escopos DIFERENTES,
     * de propósito, porque é o que o aviso precisa dizer ("contém 3 subpastas e 127 arquivos"):
     *
     *   - subpastas: só as DESCENDENTES; a própria $secao não se conta;
     *   - arquivos:  os da própria $secao MAIS os de toda a descendência.
     *
     * Nunca inclui os documentos que estão na raiz da pasta (secao_id IS NULL) — esses sobrevivem.
     *
     * @return array{subpastas: int, arquivos: int}
     */
    public function contarConteudoRecursivo(PastaSecao $secao): array
    {
        $subpastas = 0;
        $arquivos  = $secao->getDocumentos()->count();

        foreach ($secao->getFilhas() as $filha) {
            $abaixo = $this->contarConteudoRecursivo($filha);
            $subpastas += 1 + $abaixo['subpastas'];
            $arquivos  += $abaixo['arquivos'];
        }

        return ['subpastas' => $subpastas, 'arquivos' => $arquivos];
    }

    public function findByIdAndPastaAndTenant(int $id, Pasta $pasta, Tenant $tenant): ?PastaSecao
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.id = :id')
            ->andWhere('s.pasta = :pasta')
            ->andWhere('s.tenant = :tenant')
            ->setParameter('id', $id)
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function salvar(PastaSecao $secao, bool $flush = false): void
    {
        $this->getEntityManager()->persist($secao);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(PastaSecao $secao, bool $flush = false): void
    {
        $this->getEntityManager()->remove($secao);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
