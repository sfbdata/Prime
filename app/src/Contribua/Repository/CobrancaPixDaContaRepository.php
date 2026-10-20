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
    /** Classe da trava de emissão de boleto (primeiro argumento de pg_advisory_xact_lock). */
    private const TRAVA_BOLETO = 80801;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CobrancaPixDaConta::class);
    }

    /** A cobrança PIX aberta desta conta e deste valor que ainda serve (não venceu). Escritório filtrado à mão; boleto fica de fora. */
    public function abertaParaReusar(Tenant $tenant, string $banco, string $ambiente, int $valorCentavos, \DateTimeImmutable $hoje): ?CobrancaPixDaConta
    {
        $abertas = $this->findBy(['tenant' => $tenant, 'forma' => CobrancaPixDaConta::FORMA_PIX, 'banco' => $banco, 'ambiente' => $ambiente, 'valorCentavos' => $valorCentavos, 'situacao' => CobrancaPixDaConta::ABERTA], ['id' => 'DESC'], 5);
        foreach ($abertas as $c) {
            if ($c->serveEm($hoje)) {
                return $c;
            }
        }

        return null;
    }

    /**
     * Quantas cobranças desta forma (PIX ou boleto) a conta abriu desde `desde` (limite por dia; cada forma tem o
     * seu). Com `$valorCentavos`, só as daquele valor (o n-ésimo boleto do valor, na chave de idempotência).
     */
    public function criadasDesde(Tenant $tenant, \DateTimeImmutable $desde, string $forma = CobrancaPixDaConta::FORMA_PIX, ?int $valorCentavos = null): int
    {
        $qb = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.tenant = :tenant')
            ->andWhere('c.forma = :forma')
            ->andWhere('c.criadaEm >= :desde')
            ->setParameter('tenant', $tenant)
            ->setParameter('forma', $forma)
            ->setParameter('desde', $desde);
        if (null !== $valorCentavos) {
            $qb->andWhere('c.valorCentavos = :valor')->setParameter('valor', $valorCentavos);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** O boleto aberto que a conta pediu hoje neste valor (um por escritório, valor e dia). Escritório filtrado à mão. */
    public function boletoAbertoDoDia(Tenant $tenant, string $banco, string $ambiente, int $valorCentavos, \DateTimeImmutable $hoje): ?CobrancaPixDaConta
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.tenant = :tenant')
            ->andWhere('c.forma = :boleto')
            ->andWhere('c.banco = :banco')
            ->andWhere('c.ambiente = :ambiente')
            ->andWhere('c.valorCentavos = :valor')
            ->andWhere('c.situacao = :aberta')
            ->andWhere('c.criadaEm >= :hoje')
            ->setParameter('tenant', $tenant)
            ->setParameter('boleto', CobrancaPixDaConta::FORMA_BOLETO)
            ->setParameter('banco', $banco)
            ->setParameter('ambiente', $ambiente)
            ->setParameter('valor', $valorCentavos)
            ->setParameter('aberta', CobrancaPixDaConta::ABERTA)
            ->setParameter('hoje', $hoje->setTime(0, 0))
            ->orderBy('c.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Roda `$acao` numa transação com a trava de boleto da conta (`pg_advisory_xact_lock`, solta no commit ou
     * no rollback). Dois cliques ao mesmo tempo esperam um pelo outro e o segundo acha o boleto do primeiro.
     * Falha dentro da ação desfaz tudo e sobe.
     *
     * @template T
     *
     * @param callable(): T $acao
     *
     * @return T
     */
    public function comTravaDeBoleto(Tenant $tenant, callable $acao): mixed
    {
        $conexao = $this->getEntityManager()->getConnection();
        $conexao->beginTransaction();
        try {
            $conexao->executeStatement('SELECT pg_advisory_xact_lock(?, ?)', [self::TRAVA_BOLETO, (int) $tenant->getId()]);
            $resultado = $acao();
            $conexao->commit();

            return $resultado;
        } catch (\Throwable $e) {
            $conexao->rollBack();

            throw $e;
        }
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
