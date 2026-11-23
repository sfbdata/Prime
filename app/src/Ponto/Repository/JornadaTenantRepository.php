<?php

namespace App\Ponto\Repository;

use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JornadaTenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class JornadaTenantRepository extends ServiceEntityRepository
{
    use ConsultaSemFiltroDeEscritorio;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, JornadaTenant::class);
    }

    /**
     * A jornada padrão do escritório pedido, mesmo de dentro de uma sessão de outro escritório (o choque).
     *
     * Os blocos vêm na mesma consulta, ainda sem o filtro: os blocos têm escritório próprio (fatia 6 de
     * docs/specs/isolamento-multitenant-cobertura.md), e a coleção carregada depois, já com o filtro de volta no
     * escritório da sessão, chegaria vazia. O choque leria o horário de B como se não existisse.
     */
    public function doEscritorio(Tenant $tenant): ?JornadaTenant
    {
        return $this->semFiltroDeEscritorio(fn (): ?JornadaTenant => $this->createQueryBuilder('j')
            ->leftJoin('j.blocos', 'b')
            ->addSelect('b')
            ->andWhere('j.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->addOrderBy('b.id', 'ASC')
            ->getQuery()
            ->getOneOrNullResult());
    }
}
