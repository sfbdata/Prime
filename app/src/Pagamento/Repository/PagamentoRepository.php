<?php

declare(strict_types=1);

namespace App\Pagamento\Repository;

use App\Pagamento\Entity\Pagamento;
use App\Pagamento\Enum\OrigemDoPagamento;
use App\Pagamento\Enum\StatusDoPagamento;
use App\ServiceDesk\Repository\SemFiltroDeTenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Pagamento>
 */
// Não-final: os testes de UseCase mockam o repositório.
class PagamentoRepository extends ServiceEntityRepository
{
    // O Master/Equipe da Central age na demanda de OUTRO escritório: as consultas do consumidor passam por cima do filtro.
    use SemFiltroDeTenant;

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
        return $this->semFiltroDeTenant(fn (): ?Pagamento => $this->findOneBy(['referencia' => $referencia]));
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
        return $this->semFiltroDeTenant(fn (): ?Pagamento => $this->createQueryBuilder('p')
            ->andWhere('IDENTITY(p.tenant) = :t')->setParameter('t', $tenantId)
            ->andWhere('p.origem = :o')->setParameter('o', $origem)
            ->andWhere('p.origemId = :i')->setParameter('i', $origemId)
            ->orderBy('p.id', 'DESC')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult());
    }

    public function contarDaOrigem(int $tenantId, OrigemDoPagamento $origem, int $origemId): int
    {
        return (int) $this->semFiltroDeTenant(fn (): mixed => $this->createQueryBuilder('p')->select('COUNT(p.id)')
            ->andWhere('IDENTITY(p.tenant) = :t')->setParameter('t', $tenantId)
            ->andWhere('p.origem = :o')->setParameter('o', $origem)
            ->andWhere('p.origemId = :i')->setParameter('i', $origemId)
            ->getQuery()->getSingleScalarResult());
    }

    /**
     * Vencidas que a conciliação pode expirar sem reconsultar: nunca chegaram ao banco (sem id externo) ou venceram há
     * mais de `$ateVencidaHa` (depois disso só expiram).
     *
     * @return list<Pagamento>
     */
    public function vencidasSemConsulta(\DateTimeImmutable $agora, \DateInterval $ateVencidaHa, int $limite = 200): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.status = :s')->setParameter('s', StatusDoPagamento::Aguardando)
            ->andWhere('p.expiraEm IS NOT NULL AND p.expiraEm <= :agora')->setParameter('agora', $agora)
            ->andWhere('p.idExterno IS NULL OR p.expiraEm < :antigo')->setParameter('antigo', $agora->sub($ateVencidaHa))
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

    /** Todos os que carregam o mesmo id no banco: mais de um é defeito de dados, nunca se escolhe um à sorte. @return list<Pagamento> */
    public function todosPorIdExterno(string $provedor, string $idExterno): array
    {
        return $this->findBy(['provedor' => $provedor, 'idExterno' => $idExterno]);
    }

    /**
     * Cobranças `aguardando` com id no banco que a conciliação reconsulta: criadas há mais de `$minimoDeIdade` (o aviso
     * ainda pode chegar sozinho) e vencidas há menos de `$ateVencidaHa` (depois disso só expiram). Sem validade, só enquanto novas. As que vencem primeiro vão
     * na frente, para o lote não ficar preso nas que ainda têm dias.
     *
     * @return list<Pagamento>
     */
    public function aguardandoParaConciliar(\DateTimeImmutable $agora, \DateInterval $minimoDeIdade, \DateInterval $ateVencidaHa, int $limite = 200): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.status = :s')->setParameter('s', StatusDoPagamento::Aguardando)
            ->andWhere('p.idExterno IS NOT NULL')
            ->andWhere('p.criadoEm <= :criadaAte')->setParameter('criadaAte', $agora->sub($minimoDeIdade))
            ->andWhere('(p.expiraEm IS NOT NULL AND p.expiraEm >= :vencidaDesde) OR (p.expiraEm IS NULL AND p.criadoEm >= :vencidaDesde)')->setParameter('vencidaDesde', $agora->sub($ateVencidaHa))
            ->orderBy('p.expiraEm', 'ASC')->addOrderBy('p.id')->setMaxResults($limite)
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
