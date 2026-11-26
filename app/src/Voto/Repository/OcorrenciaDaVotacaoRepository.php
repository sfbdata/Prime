<?php

declare(strict_types=1);

namespace App\Voto\Repository;

use App\Voto\Entity\Assembleia;
use App\Voto\Entity\OcorrenciaDaVotacao;
use App\Voto\Entity\UnidadeDaAssembleia;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Toda busca filtra pelo escritório por escrito (o da assembleia ou da unidade informada), sem depender do filtro da
 * sessão: a cédula do eleitor roda com o `TenantFilter` desligado.
 *
 * @extends ServiceEntityRepository<OcorrenciaDaVotacao>
 */
// Não-final: os testes de UseCase do Voto o substituem por mock.
class OcorrenciaDaVotacaoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OcorrenciaDaVotacao::class);
    }

    public function salvar(OcorrenciaDaVotacao $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** Quantas ocorrências a unidade já registrou nesta assembleia (o limite é por eleitor e por assembleia). */
    public function contarDaUnidade(UnidadeDaAssembleia $unidade): int
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->andWhere('o.unidade = :unidade AND o.assembleia = :assembleia AND o.tenant = :tenant')
            ->setParameter('unidade', $unidade)
            ->setParameter('assembleia', $unidade->getAssembleia())
            ->setParameter('tenant', $unidade->getTenant())
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * As ocorrências da assembleia, da mais antiga para a mais recente (ordem em que a ata as conta).
     *
     * @return list<OcorrenciaDaVotacao>
     */
    public function listarDaAssembleia(Assembleia $assembleia): array
    {
        /** @var list<OcorrenciaDaVotacao> $lista */
        $lista = $this->createQueryBuilder('o')
            ->addSelect('p')
            ->leftJoin('o.pauta', 'p')
            ->andWhere('o.assembleia = :assembleia AND o.tenant = :tenant')
            ->setParameter('assembleia', $assembleia)
            ->setParameter('tenant', $assembleia->getTenant())
            ->orderBy('o.criadaEm', 'ASC')
            ->addOrderBy('o.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $lista;
    }
}
