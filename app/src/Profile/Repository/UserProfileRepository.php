<?php
declare(strict_types=1);
namespace App\Profile\Repository;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Profile\Entity\UserProfile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class UserProfileRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserProfile::class);
    }

    public function salvar(UserProfile $perfil, bool $flush = false): void
    {
        $this->getEntityManager()->persist($perfil);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(UserProfile $perfil, bool $flush = false): void
    {
        $this->getEntityManager()->remove($perfil);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function buscarPorUsuario(User $user): ?UserProfile
    {
        return $this->findOneBy(['user' => $user]);
    }

    public function buscarPorFotoUrlETenant(string $fotoUrl, Tenant $tenant): ?UserProfile
    {
        return $this->getEntityManager()
            ->createQuery(
                'SELECT up FROM App\Profile\Entity\UserProfile up
                 JOIN up.user u
                 JOIN App\Entity\Auth\UserTenant ut WITH ut.user = u
                 WHERE up.fotoUrl  = :fotoUrl
                   AND ut.tenant   = :tenant
                   AND ut.isActive = true'
            )
            ->setParameter('fotoUrl', $fotoUrl)
            ->setParameter('tenant', $tenant)
            ->getOneOrNullResult();
    }
}
