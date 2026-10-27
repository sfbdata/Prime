<?php

declare(strict_types=1);

namespace App\Pagamento\Repository;

use App\Pagamento\Entity\Pagamento;
use App\Pagamento\Enum\OrigemDoPagamento;
use App\Pagamento\Enum\StatusDoPagamento;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Pagamento>
 */
// Não-final: os testes de UseCase mockam o repositório.
class PagamentoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Pagamento::class);
    }

    public function salvar(Pagamento $pagamento): void
    {
        $this->getEntityManager()->persist($pagamento);
    }

    /**
     * Só para o aviso do banco, que chega sem usuário e resolve o escritório pela própria cobrança (spec §6.5): o
     * TenantFilter fica inerte sem escritório no contexto. Tela nenhuma usa isto.
     */
    public function porReferenciaDoAviso(string $referencia): ?Pagamento
    {
        return $this->findOneBy(['referencia' => $referencia]);
    }

    /** O mesmo end_to_end_id já está em OUTRA cobrança? (o pagamento do banco só vale uma vez) */
    public function endToEndEmOutra(string $endToEndId, Pagamento $pagamento): bool
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->andWhere('p.endToEndId = :e')->setParameter('e', $endToEndId)
            ->andWhere('p.id != :id')->setParameter('id', $pagamento->getId())
            ->getQuery()->getSingleScalarResult() > 0;
    }

    /** `SELECT … FOR UPDATE`: duas confirmações do mesmo pagamento não passam as duas. Dentro de uma transação. */
    public function travar(Pagamento $pagamento): void
    {
        $this->getEntityManager()->refresh($pagamento, LockMode::PESSIMISTIC_WRITE);
    }

    /** O pagamento mais recente de um consumidor, do escritório dele. */
    public function ultimoDaOrigem(int $tenantId, OrigemDoPagamento $origem, int $origemId): ?Pagamento
    {
        return $this->createQueryBuilder('p')
            ->andWhere('IDENTITY(p.tenant) = :t')->setParameter('t', $tenantId)
            ->andWhere('p.origem = :o')->setParameter('o', $origem)
            ->andWhere('p.origemId = :i')->setParameter('i', $origemId)
            ->orderBy('p.id', 'DESC')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    /** Cobranças aguardando que já passaram da validade: a conciliação as marca expiradas. @return list<Pagamento> */
    public function vencidasAguardando(\DateTimeImmutable $agora, int $limite = 200): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.status = :s')->setParameter('s', StatusDoPagamento::Aguardando)
            ->andWhere('p.expiraEm IS NOT NULL AND p.expiraEm <= :agora')->setParameter('agora', $agora)
            ->orderBy('p.id')->setMaxResults($limite)
            ->getQuery()->getResult();
    }

    /**
     * O pagamento que o banco chama por aquele id (o aviso e a reconsulta chegam com o id da cobrança no banco).
     * Sem escritório no contexto, como {@see porReferenciaDoAviso}.
     */
    public function porIdExterno(string $provedor, string $idExterno): ?Pagamento
    {
        return $this->findOneBy(['provedor' => $provedor, 'idExterno' => $idExterno]);
    }

    /**
     * Cobranças `aguardando` com id no banco que a conciliação reconsulta: criadas há mais de `$minimoDeIdade` (o aviso
     * ainda pode chegar sozinho) e vencidas há menos de `$ateVencidaHa` (depois disso só expiram).
     *
     * @return list<Pagamento>
     */
    public function aguardandoParaConciliar(\DateTimeImmutable $agora, \DateInterval $minimoDeIdade, \DateInterval $ateVencidaHa, int $limite = 200): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.status = :s')->setParameter('s', StatusDoPagamento::Aguardando)
            ->andWhere('p.idExterno IS NOT NULL')
            ->andWhere('p.criadoEm <= :criadaAte')->setParameter('criadaAte', $agora->sub($minimoDeIdade))
            ->andWhere('p.expiraEm IS NULL OR p.expiraEm >= :vencidaDesde')->setParameter('vencidaDesde', $agora->sub($ateVencidaHa))
            ->orderBy('p.id')->setMaxResults($limite)
            ->getQuery()->getResult();
    }

    /** Divergentes ainda sem decisão do Master (o resumo diário). @return list<Pagamento> */
    public function divergentesAbertos(int $limite = 200): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.status = :s')->setParameter('s', StatusDoPagamento::Divergente)
            ->orderBy('p.id')->setMaxResults($limite)
            ->getQuery()->getResult();
    }
}
