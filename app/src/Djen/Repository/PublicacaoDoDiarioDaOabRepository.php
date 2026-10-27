<?php

declare(strict_types=1);

namespace App\Djen\Repository;

use App\Djen\Entity\PublicacaoDoDiarioDaOab;
use App\Djen\Enum\TipoDeAtoDoDiario;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PublicacaoDoDiarioDaOab>
 */
class PublicacaoDoDiarioDaOabRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PublicacaoDoDiarioDaOab::class);
    }

    public function salvar(PublicacaoDoDiarioDaOab $publicacao): void
    {
        $this->getEntityManager()->persist($publicacao);
        $this->getEntityManager()->flush();
    }

    /**
     * Publicações de quem registrou, da mais recente para a mais antiga.
     *
     * @return list<PublicacaoDoDiarioDaOab>
     */
    public function listarDoUsuario(Tenant $tenant, User $usuario, ?TipoDeAtoDoDiario $tipo, string $busca): array
    {
        $consulta = $this->doUsuario($tenant, $usuario)->orderBy('d.dataPublicacao', 'DESC')->addOrderBy('d.id', 'DESC');
        if ($tipo !== null) {
            $consulta->andWhere('d.tipo = :tipo')->setParameter('tipo', $tipo);
        }
        $busca = trim($busca);
        if ($busca !== '') {
            $consulta->andWhere('LOWER(d.numeroProcesso) LIKE :busca OR LOWER(d.ato) LIKE :busca OR LOWER(d.texto) LIKE :busca')
                ->setParameter('busca', '%' . mb_strtolower(addcslashes($busca, '%_\\')) . '%');
        }

        return $consulta->getQuery()->getResult();
    }

    public function contarNaoLidasDoUsuario(Tenant $tenant, User $usuario): int
    {
        return (int) $this->doUsuario($tenant, $usuario)->select('COUNT(d.id)')->andWhere('d.lidaEm IS NULL')->getQuery()->getSingleScalarResult();
    }

    private function doUsuario(Tenant $tenant, User $usuario): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('d')
            ->andWhere('d.tenant = :tenant')
            ->andWhere('d.usuario = :usuario')
            ->andWhere('d.excluidaEm IS NULL')
            ->setParameter('tenant', $tenant)
            ->setParameter('usuario', $usuario);
    }
}
