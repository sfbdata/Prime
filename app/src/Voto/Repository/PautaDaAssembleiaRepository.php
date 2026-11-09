<?php

declare(strict_types=1);

namespace App\Voto\Repository;

use App\Voto\Entity\Assembleia;
use App\Voto\Entity\PautaDaAssembleia;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Toda busca recebe a assembleia e filtra pelo escritório DELA, por escrito: a cédula pública roda com o TenantFilter
 * desligado e não pode depender dele.
 *
 * @extends ServiceEntityRepository<PautaDaAssembleia>
 */
// Não-final: os testes de UseCase do Voto o substituem por mock.
class PautaDaAssembleiaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PautaDaAssembleia::class);
    }

    public function salvar(PautaDaAssembleia $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(PautaDaAssembleia $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entidade);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** Guarda de posse: a pauta pelo id, só se for da assembleia (e do escritório dela). */
    public function buscarDaAssembleia(int $id, Assembleia $assembleia): ?PautaDaAssembleia
    {
        return $this->findOneBy(['id' => $id, 'assembleia' => $assembleia, 'tenant' => $assembleia->getTenant()]);
    }

    /**
     * A pauta da assembleia com a linha TRAVADA (SELECT ... FOR UPDATE) e o estado relido do banco, para o voto se
     * serializar com o encerramento. Exige transação aberta (Doctrine lança TransactionRequiredException sem ela).
     */
    public function buscarTravadaDaAssembleia(int $id, Assembleia $assembleia): ?PautaDaAssembleia
    {
        /** @var PautaDaAssembleia|null $pauta */
        $pauta = $this->createQueryBuilder('p')
            ->andWhere('p.id = :id')
            ->andWhere('p.assembleia = :assembleia')
            ->andWhere('p.tenant = :tenant')
            ->setParameter('id', $id)
            ->setParameter('assembleia', $assembleia)
            ->setParameter('tenant', $assembleia->getTenant())
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $pauta;
    }
}
