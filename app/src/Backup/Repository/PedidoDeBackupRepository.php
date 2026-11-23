<?php

declare(strict_types=1);

namespace App\Backup\Repository;

use App\Backup\Entity\PedidoDeBackup;
use App\Backup\Enum\SituacaoDoBackup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Pedidos de backup. Toda consulta leva o escritório ESCRITO NA MÃO (`tenant = :tenant`), nunca o `TenantFilter`: o
 * worker do Messenger e o agendador rodam fora de request, onde o filtro não liga (spec backup-e-exportacao-de-dados,
 * seção 2, regra 2). A única consulta sem escritório é a dos vencidos, que só lê para a tarefa agendada vencer.
 *
 * @extends ServiceEntityRepository<PedidoDeBackup>
 */
class PedidoDeBackupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PedidoDeBackup::class);
    }

    public function salvar(PedidoDeBackup $pedido, bool $flush = false): void
    {
        $this->getEntityManager()->persist($pedido);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** O pedido pelo id, só se for do escritório informado. */
    public function doEscritorio(int $id, int $tenantId): ?PedidoDeBackup
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.id = :id')
            ->andWhere('IDENTITY(p.tenant) = :tenant')
            ->setParameter('id', $id)
            ->setParameter('tenant', $tenantId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Os últimos pedidos de uma pessoa num escritório, o mais novo primeiro (a lista da tela).
     *
     * @return list<PedidoDeBackup>
     */
    public function ultimosDaPessoa(int $tenantId, int $usuarioId, int $limite = 10): array
    {
        /** @var list<PedidoDeBackup> */
        return $this->daPessoa($tenantId, $usuarioId)
            ->orderBy('p.pedidoEm', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    /** A pessoa já tem um pedido na fila ou em preparo neste escritório. */
    public function temAbertoDaPessoa(int $tenantId, int $usuarioId): bool
    {
        return null !== $this->daPessoa($tenantId, $usuarioId)
            ->select('p.id')
            ->andWhere('p.situacao IN (:abertas)')
            ->setParameter('abertas', [SituacaoDoBackup::Pedido, SituacaoDoBackup::Montando])
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Quantos pedidos a pessoa fez neste escritório desde `$desde` (o limite de 1 por dia conta 24h corridas). */
    public function quantosDaPessoaDesde(int $tenantId, int $usuarioId, \DateTimeImmutable $desde): int
    {
        return (int) $this->daPessoa($tenantId, $usuarioId)
            ->select('COUNT(p.id)')
            ->andWhere('p.pedidoEm >= :desde')
            ->setParameter('desde', $desde)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Os backups prontos que já passaram da validade, de qualquer escritório: só a tarefa agendada os lê, para vencer
     * cada um e apagar o ZIP no escopo do escritório do próprio pedido.
     *
     * @return list<PedidoDeBackup>
     */
    public function prontosVencidos(\DateTimeImmutable $agora, int $limite = 200): array
    {
        /** @var list<PedidoDeBackup> */
        return $this->createQueryBuilder('p')
            ->andWhere('p.situacao = :pronto')
            ->andWhere('p.expiraEm <= :agora')
            ->setParameter('pronto', SituacaoDoBackup::Pronto)
            ->setParameter('agora', $agora)
            ->orderBy('p.id', 'ASC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    private function daPessoa(int $tenantId, int $usuarioId): QueryBuilder
    {
        return $this->createQueryBuilder('p')
            ->andWhere('IDENTITY(p.tenant) = :tenant')
            ->andWhere('IDENTITY(p.usuario) = :usuario')
            ->setParameter('tenant', $tenantId)
            ->setParameter('usuario', $usuarioId);
    }
}
