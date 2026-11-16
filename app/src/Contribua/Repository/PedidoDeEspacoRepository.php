<?php

declare(strict_types=1);

namespace App\Contribua\Repository;

use App\Contribua\Entity\PedidoDeEspaco;
use App\Contribua\Enum\SituacaoDoPedidoDeEspaco;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PedidoDeEspaco>
 */
// Não-final: os testes de UseCase mockam o repositório.
class PedidoDeEspacoRepository extends ServiceEntityRepository
{
    public const LIMITE_DO_PAINEL = 30;

    /** Classe de trava (pg_advisory_xact_lock): os pedidos de espaço de um escritório. */
    public const CLASSE_TRAVA = 4303;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PedidoDeEspaco::class);
    }

    /**
     * Roda o trabalho numa transação com a trava do escritório: dois pedidos (ou duas decisões) simultâneos se
     * enfileiram e o segundo enxerga o primeiro. Assim "um pendente por escritório" e "só decide pendente" valem
     * também com clique duplo ou duas abas. O trabalho não deve lançar exceção (o EntityManager fecharia): devolve o
     * resultado e quem chama decide.
     *
     * @template T
     *
     * @param \Closure(): T $trabalho
     *
     * @return T
     */
    public function emTransacaoTravada(int $tenantId, \Closure $trabalho): mixed
    {
        return $this->getEntityManager()->wrapInTransaction(function () use ($tenantId, $trabalho): mixed {
            $this->getEntityManager()->getConnection()->executeStatement('SELECT pg_advisory_xact_lock(?, ?)', [self::CLASSE_TRAVA, $tenantId]);

            return $trabalho();
        });
    }

    /** Relê o pedido do banco (depois da trava, para ver a decisão de quem chegou antes). */
    public function recarregar(PedidoDeEspaco $pedido): void
    {
        $this->getEntityManager()->refresh($pedido);
    }

    public function salvar(PedidoDeEspaco $pedido, bool $flush = false): void
    {
        $this->getEntityManager()->persist($pedido);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** O pedido em análise do escritório, se houver (um por escritório). */
    public function pendenteDoEscritorio(int $tenantId): ?PedidoDeEspaco
    {
        return $this->createQueryBuilder('p')
            ->andWhere('IDENTITY(p.tenant) = :tenant')
            ->andWhere('p.situacao = :pendente')
            ->setParameter('tenant', $tenantId)
            ->setParameter('pendente', SituacaoDoPedidoDeEspaco::Pendente)
            ->orderBy('p.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** O pedido mais recente do escritório, qualquer que seja a situação. */
    public function ultimoDoEscritorio(int $tenantId): ?PedidoDeEspaco
    {
        return $this->createQueryBuilder('p')
            ->andWhere('IDENTITY(p.tenant) = :tenant')
            ->setParameter('tenant', $tenantId)
            ->orderBy('p.criadoEm', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Os pedidos de TODOS os escritórios, mais recentes primeiro, para o painel do Master. Só dentro da
     * `VisaoDoMaster` (o filtro de tenant da sessão limitaria ao escritório dele).
     *
     * @return list<PedidoDeEspaco>
     */
    public function maisRecentesParaOMaster(int $limite = self::LIMITE_DO_PAINEL): array
    {
        return $this->createQueryBuilder('p')
            ->addSelect('t', 'u')
            ->join('p.tenant', 't')
            ->leftJoin('p.pedidoPor', 'u')
            ->orderBy('p.criadoEm', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    /**
     * "N pendente(s) · M liberado(s)" do painel do Master, em todos os escritórios (só dentro da `VisaoDoMaster`).
     *
     * @return array{pendentes: int, liberados: int}
     */
    public function contarParaOMaster(): array
    {
        $linhas = $this->createQueryBuilder('p')
            ->select('p.situacao AS situacao', 'COUNT(p.id) AS total')
            ->groupBy('p.situacao')
            ->getQuery()
            ->getArrayResult();

        $contagem = ['pendentes' => 0, 'liberados' => 0];
        foreach ($linhas as $linha) {
            $situacao = $linha['situacao'] instanceof SituacaoDoPedidoDeEspaco ? $linha['situacao'] : SituacaoDoPedidoDeEspaco::from((string) $linha['situacao']);
            if ($situacao === SituacaoDoPedidoDeEspaco::Pendente) {
                $contagem['pendentes'] = (int) $linha['total'];
            } elseif ($situacao === SituacaoDoPedidoDeEspaco::Liberado) {
                $contagem['liberados'] = (int) $linha['total'];
            }
        }

        return $contagem;
    }
}
