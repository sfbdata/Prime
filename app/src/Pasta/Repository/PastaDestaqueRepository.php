<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDestaque;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Destaques de linha da Lista do Expediente. Toda consulta é por escritório E por usuário: o destaque
 * é de quem marcou.
 *
 * @extends ServiceEntityRepository<PastaDestaque>
 */
class PastaDestaqueRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PastaDestaque::class);
    }

    public function buscarDoUsuario(Pasta $pasta, User $usuario, Tenant $tenant): ?PastaDestaque
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.tenant = :tenant')
            ->andWhere('d.usuario = :usuario')
            ->andWhere('d.pasta = :pasta')
            ->setParameter('tenant', $tenant)
            ->setParameter('usuario', $usuario)
            ->setParameter('pasta', $pasta)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Cor do destaque por id de pasta, só do usuário e do escritório (`[pastaId => 'amarelo']`).
     *
     * @return array<int, string>
     */
    public function coresDoUsuario(User $usuario, Tenant $tenant): array
    {
        $linhas = $this->createQueryBuilder('d')
            ->select('IDENTITY(d.pasta) AS pasta_id', 'd.cor')
            ->andWhere('d.tenant = :tenant')
            ->andWhere('d.usuario = :usuario')
            ->setParameter('tenant', $tenant)
            ->setParameter('usuario', $usuario)
            ->getQuery()
            ->getArrayResult();

        $cores = [];
        foreach ($linhas as $l) {
            $cores[(int) $l['pasta_id']] = (string) $l['cor'];
        }

        return $cores;
    }
}
