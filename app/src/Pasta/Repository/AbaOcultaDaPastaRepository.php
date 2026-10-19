<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\AbaDaPasta;
use App\Pasta\Entity\AbaOcultaDaPasta;
use App\Pasta\Entity\Pasta;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Regras de aba oculta da pasta. Toda consulta leva o tenant explícito: a barreira das rotas roda
 * antes do controller e não confia só no `TenantFilter`.
 *
 * @extends ServiceEntityRepository<AbaOcultaDaPasta>
 */
class AbaOcultaDaPastaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AbaOcultaDaPasta::class);
    }

    public function salvar(AbaOcultaDaPasta $regra, bool $flush = false): void
    {
        $this->getEntityManager()->persist($regra);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remover(AbaOcultaDaPasta $regra, bool $flush = false): void
    {
        $this->getEntityManager()->remove($regra);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Roda `$operacao` numa transação com a linha da pasta travada (`SELECT … FOR UPDATE`). Duas
     * pessoas gravando a PRIMEIRA regra da mesma aba ao mesmo tempo esbarrariam no UNIQUE
     * (pasta_id, aba) e a segunda levaria 500; com a trava, a segunda espera, relê a regra que a
     * primeira criou e a atualiza. Entidade normal (não SQL cru), para a auditoria seguir valendo.
     *
     * @template T
     *
     * @param callable(): T $operacao
     *
     * @return T
     */
    public function comTravaDaPasta(Pasta $pasta, callable $operacao): mixed
    {
        return $this->getEntityManager()->wrapInTransaction(static function (EntityManagerInterface $em) use ($pasta, $operacao): mixed {
            $em->getConnection()->fetchOne('SELECT id FROM pasta WHERE id = :id FOR UPDATE', ['id' => $pasta->getId()]);

            return $operacao();
        });
    }

    public function daAba(Pasta $pasta, AbaDaPasta $aba, Tenant $tenant): ?AbaOcultaDaPasta
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.tenant = :tenant')
            ->andWhere('r.pasta = :pasta')
            ->andWhere('r.aba = :aba')
            ->setParameter('tenant', $tenant)
            ->setParameter('pasta', $pasta)
            ->setParameter('aba', $aba->value)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Todas as regras da pasta, numa consulta só (a tela da pasta precisa de todas de uma vez).
     *
     * @return list<AbaOcultaDaPasta>
     */
    public function daPasta(Pasta $pasta, Tenant $tenant): array
    {
        /** @var list<AbaOcultaDaPasta> $regras */
        $regras = $this->createQueryBuilder('r')
            ->andWhere('r.tenant = :tenant')
            ->andWhere('r.pasta = :pasta')
            ->setParameter('tenant', $tenant)
            ->setParameter('pasta', $pasta)
            ->getQuery()
            ->getResult();

        return $regras;
    }
}
