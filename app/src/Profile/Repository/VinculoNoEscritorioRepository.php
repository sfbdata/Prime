<?php

declare(strict_types=1);

namespace App\Profile\Repository;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Profile\Entity\VinculoNoEscritorio;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VinculoNoEscritorio>
 */
class VinculoNoEscritorioRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VinculoNoEscritorio::class);
    }

    public function doUsuarioNoEscritorio(Tenant $tenant, User $usuario): ?VinculoNoEscritorio
    {
        return $this->findOneBy(['tenant' => $tenant, 'usuario' => $usuario]);
    }

    public function salvar(VinculoNoEscritorio $vinculo, bool $flush = false): void
    {
        $this->getEntityManager()->persist($vinculo);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
