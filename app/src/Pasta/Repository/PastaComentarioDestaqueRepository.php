<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaComentarioDestaque;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PastaComentarioDestaque>
 */
class PastaComentarioDestaqueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PastaComentarioDestaque::class);
    }

    /** Com a pasta travada: dois primeiros destaques da mesma aba ao mesmo tempo não viram 500 no UNIQUE. */
    public function comTravaDaPasta(Pasta $pasta, callable $operacao): mixed
    {
        return $this->getEntityManager()->wrapInTransaction(static function (EntityManagerInterface $em) use ($pasta, $operacao): mixed {
            $em->getConnection()->fetchOne('SELECT id FROM pasta WHERE id = :id FOR UPDATE', ['id' => $pasta->getId()]);

            return $operacao();
        });
    }

    public function daAba(Pasta $pasta, string $aba, Tenant $tenant): ?PastaComentarioDestaque
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.tenant = :tenant')
            ->andWhere('d.pasta = :pasta')
            ->andWhere('d.aba = :aba')
            ->setParameter('tenant', $tenant)
            ->setParameter('pasta', $pasta)
            ->setParameter('aba', $aba)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Os destaques da pasta para a tela: aba => [comentário => cor]. No máximo um por aba.
     *
     * @return array<string, array<int, string>>
     */
    public function mapaDaPasta(Pasta $pasta, Tenant $tenant): array
    {
        $mapa = [];
        foreach ($this->findBy(['pasta' => $pasta, 'tenant' => $tenant]) as $destaque) {
            $mapa[$destaque->getAba()][$destaque->comentarioId()] = $destaque->getCor();
        }

        return $mapa;
    }

    public function salvar(PastaComentarioDestaque $destaque): void
    {
        $this->getEntityManager()->persist($destaque);
        $this->getEntityManager()->flush();
    }

    public function remover(PastaComentarioDestaque $destaque): void
    {
        $this->getEntityManager()->remove($destaque);
        $this->getEntityManager()->flush();
    }
}
