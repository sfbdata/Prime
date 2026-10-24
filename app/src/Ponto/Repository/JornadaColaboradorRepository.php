<?php

declare(strict_types=1);

namespace App\Ponto\Repository;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JornadaColaborador;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Sem `final`: os testes de serviço e de UseCase mockam este repositório (skill criar-repository).
 */
class JornadaColaboradorRepository extends ServiceEntityRepository
{
    use ConsultaSemFiltroDeEscritorio;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JornadaColaborador::class);
    }

    /**
     * A jornada pessoal da pessoa NAQUELE escritório (spec seguranca-b-jornada §3.2). O escritório vai escrito na
     * consulta, então ela não depende do filtro de escritório: funciona do escritório da URL, da folha e da batida, e
     * também para o choque, que lê a jornada da mesma pessoa em outro escritório.
     */
    public function findByUserETenant(User $user, Tenant $tenant): ?JornadaColaborador
    {
        return $this->semFiltroDeEscritorio(fn (): ?JornadaColaborador => $this->createQueryBuilder('j')
            ->andWhere('j.user = :user')
            ->andWhere('j.tenant = :tenant')
            ->setParameter('user', $user)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult());
    }

    public function salvar(JornadaColaborador $jornada, bool $flush = false): void
    {
        $this->getEntityManager()->persist($jornada);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
