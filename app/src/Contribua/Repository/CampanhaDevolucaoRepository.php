<?php

declare(strict_types=1);

namespace App\Contribua\Repository;

use App\Contribua\Entity\Campanha;
use App\Contribua\Entity\CampanhaDevolucao;
use App\Contribua\Entity\CampanhaParticipacao;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CampanhaDevolucao>
 */
// Não-final: os testes de UseCase mockam o repositório.
class CampanhaDevolucaoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CampanhaDevolucao::class);
    }

    public function salvar(CampanhaDevolucao $devolucao, bool $flush = false): void
    {
        $this->getEntityManager()->persist($devolucao);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function daParticipacao(CampanhaParticipacao $participacao): ?CampanhaDevolucao
    {
        return $this->findOneBy(['participacao' => $participacao]);
    }

    /**
     * Pendentes primeiro, depois as mais recentes.
     *
     * @return list<CampanhaDevolucao>
     */
    public function daCampanha(Campanha $campanha): array
    {
        return $this->createQueryBuilder('d')->join('d.participacao', 'p')->andWhere('p.campanha = :c')->setParameter('c', $campanha)
            ->addSelect('CASE WHEN d.devolvidaEm IS NULL THEN 0 ELSE 1 END AS HIDDEN ordem')->orderBy('ordem', 'ASC')->addOrderBy('d.pedidaEm', 'DESC')->addOrderBy('d.id', 'DESC')->getQuery()->getResult();
    }
}
