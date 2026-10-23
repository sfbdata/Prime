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

    /** A jornada padrão do escritório pedido, mesmo de dentro de uma sessão de outro escritório (o choque). */
    public function doEscritorio(Tenant $tenant): ?JornadaTenant
    {
        return $this->semFiltroDeEscritorio(fn (): ?JornadaTenant => $this->findOneBy(['tenant' => $tenant]));
    }
}
