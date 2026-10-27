<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\ModeloDePeca;
use App\Pasta\Entity\ModeloDePecaFavorito;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ModeloDePecaFavorito>
 */
class ModeloDePecaFavoritoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ModeloDePecaFavorito::class);
    }

    /** @return list<int> ids dos modelos que o usuário marcou */
    public function idsDoUsuario(User $usuario, Tenant $tenant): array
    {
        $linhas = $this->createQueryBuilder('f')
            ->select('IDENTITY(f.modelo) AS modeloId')
            ->andWhere('f.usuario = :usuario')
            ->andWhere('f.tenant = :tenant')
            ->setParameter('usuario', $usuario)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $l): int => (int) $l['modeloId'], $linhas);
    }

    public function buscar(User $usuario, ModeloDePeca $modelo): ?ModeloDePecaFavorito
    {
        return $this->findOneBy(['usuario' => $usuario, 'modelo' => $modelo]);
    }

    public function salvar(ModeloDePecaFavorito $favorito, bool $flush = false): void
    {
        $this->getEntityManager()->persist($favorito);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(ModeloDePecaFavorito $favorito, bool $flush = false): void
    {
        $this->getEntityManager()->remove($favorito);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
