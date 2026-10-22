<?php

declare(strict_types=1);

namespace App\Tarefa\Repository;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tarefa\Entity\AprendizadoDaPendencia;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * As linhas de pendência que a pessoa pediu (Fase 4 de Minhas Metas). Toda consulta é presa ao escritório E ao usuário: ninguém lê o aprendizado
 * de outra pessoa nem de outro escritório (C2).
 *
 * @extends ServiceEntityRepository<AprendizadoDaPendencia>
 */
class AprendizadoDaPendenciaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AprendizadoDaPendencia::class);
    }

    /**
     * Os registros da pessoa naquele escritório, do mais novo para o mais antigo, a partir de `$pular`.
     *
     * @return list<AprendizadoDaPendencia>
     */
    public function doUsuario(Tenant $tenant, User $usuario, ?int $limite = null, int $pular = 0): array
    {
        /** @var list<AprendizadoDaPendencia> $registros */
        $registros = $this->createQueryBuilder('a')
            ->andWhere('a.tenant = :tenant')
            ->andWhere('a.usuario = :usuario')
            ->setParameter('tenant', $tenant)
            ->setParameter('usuario', $usuario)
            ->orderBy('a.criadoEm', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setFirstResult($pular)
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();

        return $registros;
    }

    public function salvar(AprendizadoDaPendencia $registro): void
    {
        $this->getEntityManager()->persist($registro);
    }

    public function remover(AprendizadoDaPendencia $registro): void
    {
        $this->getEntityManager()->remove($registro);
    }

    public function confirmar(): void
    {
        $this->getEntityManager()->flush();
    }
}
