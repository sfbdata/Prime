<?php

declare(strict_types=1);

namespace App\Conector\Repository;

use App\Conector\Entity\PropostaDoConector;
use App\Conector\Enum\EstadoDaPropostaDoConector;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Propostas que esperam confirmação. A busca leva o escritório do token explícito, sem depender do `TenantFilter`:
 * proposta de outro escritório não existe para quem pergunta.
 *
 * @extends ServiceEntityRepository<PropostaDoConector>
 */
class PropostaDoConectorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PropostaDoConector::class);
    }

    public function salvar(PropostaDoConector $entidade, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entidade);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function buscarPorIdentificadorHash(string $identificadorHash, Tenant $tenant): ?PropostaDoConector
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.identificadorHash = :hash')
            ->setParameter('tenant', $tenant)
            ->setParameter('hash', $identificadorHash)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * A mesma busca, com a linha travada (`SELECT ... FOR UPDATE`) até o fim da transação de quem chama: duas
     * `confirmar_acao` da mesma proposta saem uma depois da outra, e a segunda já enxerga a proposta decidida (6.7, item 4).
     * `HINT_REFRESH`: a linha pode já estar no gerenciador de identidades, lida antes da trava; vale o estado do banco.
     */
    public function buscarComTrava(string $identificadorHash, Tenant $tenant): ?PropostaDoConector
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.tenant = :tenant')
            ->andWhere('p.identificadorHash = :hash')
            ->setParameter('tenant', $tenant)
            ->setParameter('hash', $identificadorHash)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

    /**
     * A varredura ao registrar uma proposta nova (6.7, item 6, como o chat faz): as pendentes do escritório que já
     * venceram viram `expirada` e perdem `dados` e `resumo`. Em lote, sem auditoria: é o fim do prazo, não um ato de
     * alguém. Só do escritório dado. Devolve quantas mudaram.
     */
    public function expirarVencidas(Tenant $tenant, \DateTimeImmutable $agora): int
    {
        return (int) $this->getEntityManager()->createQuery(
            'UPDATE ' . PropostaDoConector::class . ' p'
            . ' SET p.estado = :expirada, p.dados = NULL, p.resumo = NULL, p.decididaEm = :agora'
            . ' WHERE p.tenant = :tenant AND p.estado = :pendente AND p.expiraEm <= :agora'
        )
            ->setParameter('expirada', EstadoDaPropostaDoConector::Expirada->value)
            ->setParameter('pendente', EstadoDaPropostaDoConector::Pendente->value)
            ->setParameter('tenant', $tenant)
            ->setParameter('agora', $agora, 'datetime_immutable')
            ->execute();
    }

    /**
     * Faxina (13.2): apaga, num DELETE só e em todos os escritórios, as propostas decididas (confirmada, cancelada ou
     * expirada) antes de `$decididasAntesDe` (quem chama passa "agora menos 1 dia": a `confirmar_acao` repetida logo
     * depois ainda acha a proposta e responde "já decidida") e as pendentes que venceram até `$agora`. Pendente no prazo
     * fica. Devolve quantas saíram.
     */
    public function apagarDecididasOuVencidas(\DateTimeImmutable $agora, \DateTimeImmutable $decididasAntesDe): int
    {
        return (int) $this->getEntityManager()->createQuery(
            'DELETE FROM ' . PropostaDoConector::class . ' p'
            . ' WHERE (p.estado <> :pendente AND p.decididaEm < :decididasAntesDe)'
            . ' OR (p.estado = :pendente AND p.expiraEm <= :agora)'
        )
            ->setParameter('pendente', EstadoDaPropostaDoConector::Pendente->value)
            ->setParameter('decididasAntesDe', $decididasAntesDe, 'datetime_immutable')
            ->setParameter('agora', $agora, 'datetime_immutable')
            ->execute();
    }
}
