<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\MateriaFavoritaDeModelo;
use App\Pasta\Enum\MateriaDeModelo;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MateriaFavoritaDeModelo>
 */
class MateriaFavoritaDeModeloRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MateriaFavoritaDeModelo::class);
    }

    /** @return list<MateriaDeModelo> */
    public function materiasDoUsuario(User $usuario, Tenant $tenant): array
    {
        $linhas = $this->createQueryBuilder('f')
            ->select('f.materia AS materia')
            ->andWhere('f.usuario = :usuario')
            ->andWhere('f.tenant = :tenant')
            ->setParameter('usuario', $usuario)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $l): MateriaDeModelo => $l['materia'], $linhas);
    }

    public function buscar(User $usuario, MateriaDeModelo $materia): ?MateriaFavoritaDeModelo
    {
        return $this->findOneBy(['usuario' => $usuario, 'materia' => $materia]);
    }

    public function salvar(MateriaFavoritaDeModelo $favorita, bool $flush = false): void
    {
        $this->getEntityManager()->persist($favorita);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(MateriaFavoritaDeModelo $favorita, bool $flush = false): void
    {
        $this->getEntityManager()->remove($favorita);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
