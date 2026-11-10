<?php

namespace App\Repository;

use App\Entity\Tenant\Sede;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Sede>
 */
class SedeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Sede::class);
    }

    /**
     * As sedes que valem no escritório: a encerrada (`removidaEm`, Ponto E10) fica de fora das listas e da cerca.
     *
     * @return list<Sede>
     */
    public function ativasDoEscritorio(Tenant $tenant): array
    {
        /** @var list<Sede> $sedes */
        $sedes = $this->createQueryBuilder('s')
            ->where('s.tenant = :tenant')
            ->andWhere('s.removidaEm IS NULL')
            ->setParameter('tenant', $tenant)
            ->orderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $sedes;
    }

    /** A sede do id, só se for deste escritório e não estiver encerrada. */
    public function ativaDoEscritorio(int $id, Tenant $tenant): ?Sede
    {
        /** @var Sede|null $sede */
        $sede = $this->createQueryBuilder('s')
            ->where('s.id = :id')
            ->andWhere('s.tenant = :tenant')
            ->andWhere('s.removidaEm IS NULL')
            ->setParameter('id', $id)
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->getOneOrNullResult();

        return $sede;
    }

    /**
     * Se alguma batida do ponto aponta para a sede (a FK que o gatilho da batida não deixa mudar). SQL direto: o filtro
     * de escritório do Doctrine não pode esconder a batida quando quem remove está em outro contexto (Master). Só diz
     * sim ou não, sem dado da batida.
     */
    public function temBatida(Sede $sede): bool
    {
        return (bool) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM registro_ponto WHERE sede_id = :sede)',
            ['sede' => $sede->getId()],
        );
    }
}
