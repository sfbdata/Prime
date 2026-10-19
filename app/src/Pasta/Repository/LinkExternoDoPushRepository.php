<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\LinkExternoDoPush;
use App\Pasta\Entity\Pasta;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Links externos do Push. Toda consulta por pasta leva o tenant explícito: as rotas públicas rodam
 * sem `TenantFilter`, e as internas não confiam só nele.
 *
 * @extends ServiceEntityRepository<LinkExternoDoPush>
 */
class LinkExternoDoPushRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LinkExternoDoPush::class);
    }

    public function salvar(LinkExternoDoPush $link, bool $flush = false): void
    {
        $this->getEntityManager()->persist($link);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** O endereço é único na plataforma inteira (é a chave da URL pública), não por escritório. */
    public function enderecoEmUso(string $endereco): bool
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->andWhere('l.endereco = :endereco')
            ->setParameter('endereco', $endereco)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * A porta da página pública: o endereço é a única coisa que o terceiro tem. Daqui em diante,
     * o tenant de toda consulta é o do link devolvido.
     */
    public function findOnePeloEndereco(string $endereco): ?LinkExternoDoPush
    {
        return $this->createQueryBuilder('l')
            ->innerJoin('l.pasta', 'p')->addSelect('p')
            ->innerJoin('l.criadoPor', 'u')->addSelect('u')
            ->andWhere('l.endereco = :endereco')
            ->setParameter('endereco', $endereco)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** O guarda de posse das rotas internas: o link tem de ser DESTA pasta e deste escritório. */
    public function findOneDaPasta(int $id, Pasta $pasta, Tenant $tenant): ?LinkExternoDoPush
    {
        return $this->createQueryBuilder('l')
            ->andWhere('l.id = :id')
            ->andWhere('l.pasta = :pasta')
            ->andWhere('l.tenant = :tenant')
            ->setParameter('id', $id)
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** @return LinkExternoDoPush[] mais recente primeiro */
    public function listarDaPasta(Pasta $pasta, Tenant $tenant): array
    {
        return $this->createQueryBuilder('l')
            ->innerJoin('l.criadoPor', 'u')->addSelect('u')
            ->andWhere('l.pasta = :pasta')
            ->andWhere('l.tenant = :tenant')
            ->setParameter('pasta', $pasta)
            ->setParameter('tenant', $tenant)
            ->orderBy('l.criadoEm', 'DESC')
            ->addOrderBy('l.id', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
