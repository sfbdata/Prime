<?php

declare(strict_types=1);

namespace App\Processo\Repository;

use App\Entity\Tenant\Tenant;
use App\Processo\Entity\NotaTecnica;
use App\Processo\Entity\NotaTecnicaVersao;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Versões anteriores das notas técnicas (PMC-020). Toda consulta é presa ao escritório.
 *
 * Não-final, como o `NotaTecnicaRepository`: o UseCase de edição o recebe e o teste unitário o
 * substitui por mock.
 *
 * @extends ServiceEntityRepository<NotaTecnicaVersao>
 */
class NotaTecnicaVersaoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NotaTecnicaVersao::class);
    }

    /** Só `persist`: quem dá o flush é o UseCase, junto com a edição da nota (uma escrita só). */
    public function adicionar(NotaTecnicaVersao $versao): void
    {
        $this->getEntityManager()->persist($versao);
    }

    /**
     * As versões anteriores de uma nota, da mais antiga para a mais nova (a ordem das edições).
     * Ainda sem tela: o desenho não mostra as versões (ver `NotaTecnicaVersao`).
     *
     * @return list<NotaTecnicaVersao>
     */
    public function listarDaNota(NotaTecnica $nota, Tenant $tenant): array
    {
        /** @var list<NotaTecnicaVersao> */
        return $this->createQueryBuilder('v')
            ->andWhere('v.tenant = :tenant')
            ->andWhere('v.nota = :nota')
            ->setParameter('tenant', $tenant)
            ->setParameter('nota', $nota)
            ->orderBy('v.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
