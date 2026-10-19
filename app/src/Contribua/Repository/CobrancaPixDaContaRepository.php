<?php

declare(strict_types=1);

namespace App\Contribua\Repository;

use App\Contribua\Entity\CobrancaPixDaConta;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<CobrancaPixDaConta> */
class CobrancaPixDaContaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CobrancaPixDaConta::class);
    }

    /** A cobrança aberta desta conta e deste valor que ainda serve (não venceu). Escritório filtrado à mão. */
    public function abertaParaReusar(Tenant $tenant, string $banco, string $ambiente, int $valorCentavos, \DateTimeImmutable $hoje): ?CobrancaPixDaConta
    {
        $abertas = $this->findBy(['tenant' => $tenant, 'banco' => $banco, 'ambiente' => $ambiente, 'valorCentavos' => $valorCentavos, 'situacao' => CobrancaPixDaConta::ABERTA], ['id' => 'DESC'], 5);
        foreach ($abertas as $c) {
            if ($c->serveEm($hoje)) {
                return $c;
            }
        }

        return null;
    }

    /** Quantas cobranças a conta abriu desde `desde` (limite por dia: o GET da página cria cobrança). */
    public function criadasDesde(Tenant $tenant, \DateTimeImmutable $desde): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.tenant = :tenant')
            ->andWhere('c.criadaEm >= :desde')
            ->setParameter('tenant', $tenant)
            ->setParameter('desde', $desde)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function doBanco(string $banco, string $idNoBanco): ?CobrancaPixDaConta
    {
        return $this->findOneBy(['banco' => $banco, 'idNoBanco' => $idNoBanco]);
    }

    /**
     * Abertas, registradas no banco, criadas desde `desde` (a conciliação).
     *
     * @return list<CobrancaPixDaConta>
     */
    public function abertasDesde(string $banco, string $ambiente, \DateTimeImmutable $desde): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.banco = :banco')
            ->andWhere('c.ambiente = :ambiente')
            ->setParameter('ambiente', $ambiente)
            ->andWhere('c.situacao = :aberta')
            ->andWhere('c.idNoBanco IS NOT NULL')
            ->andWhere('c.criadaEm >= :desde')
            ->setParameter('banco', $banco)
            ->setParameter('aberta', CobrancaPixDaConta::ABERTA)
            ->setParameter('desde', $desde)
            ->orderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function salvar(CobrancaPixDaConta $cobranca): void
    {
        $this->getEntityManager()->persist($cobranca);
        $this->getEntityManager()->flush();
    }
}
