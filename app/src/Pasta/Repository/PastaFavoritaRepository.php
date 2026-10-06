<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaFavorita;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Favoritos de pasta. Toda consulta é por escritório E por usuário: favorito é preferência
 * pessoal, e o de um colega não muda nada para mim.
 *
 * @extends ServiceEntityRepository<PastaFavorita>
 */
class PastaFavoritaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PastaFavorita::class);
    }

    public function buscarDoUsuario(Pasta $pasta, User $usuario, Tenant $tenant): ?PastaFavorita
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.tenant = :tenant')
            ->andWhere('f.usuario = :usuario')
            ->andWhere('f.pasta = :pasta')
            ->setParameter('tenant', $tenant)
            ->setParameter('usuario', $usuario)
            ->setParameter('pasta', $pasta)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function ehFavorita(Pasta $pasta, User $usuario, Tenant $tenant): bool
    {
        return $this->buscarDoUsuario($pasta, $usuario, $tenant) !== null;
    }

    /**
     * Ids das pastas que o usuário fixou neste escritório, como conjunto (`[id => true]`) — é o
     * formato que a listagem consulta linha a linha sem varrer array.
     *
     * @return array<int, true>
     */
    public function idsDasPastasFavoritas(User $usuario, Tenant $tenant): array
    {
        $ids = $this->createQueryBuilder('f')
            ->select('IDENTITY(f.pasta) AS pasta_id')
            ->andWhere('f.tenant = :tenant')
            ->andWhere('f.usuario = :usuario')
            ->setParameter('tenant', $tenant)
            ->setParameter('usuario', $usuario)
            ->getQuery()
            ->getSingleColumnResult();

        return array_fill_keys(array_map('intval', $ids), true);
    }

    public function salvar(PastaFavorita $favorita, bool $flush = false): void
    {
        $this->getEntityManager()->persist($favorita);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(PastaFavorita $favorita, bool $flush = false): void
    {
        $this->getEntityManager()->remove($favorita);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
