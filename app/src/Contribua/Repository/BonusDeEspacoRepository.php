<?php

declare(strict_types=1);

namespace App\Contribua\Repository;

use App\Contribua\Entity\BonusDeEspaco;
use App\Entity\Tenant\Tenant;
use App\Indicacao\Entity\Indicacao;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Bônus de espaço dos escritórios. A entidade não é TenantAware (ver {@see BonusDeEspaco}): todo método daqui
 * filtra por tenant ou indicação explícitos, e é o único lugar que a consulta.
 *
 * @extends ServiceEntityRepository<BonusDeEspaco>
 */
// Não-final: os testes de UseCase mockam o repositório.
class BonusDeEspacoRepository extends ServiceEntityRepository
{
    /** Classe de trava (pg_advisory_xact_lock): a concessão de uma indicação. */
    public const CLASSE_TRAVA = 4302;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BonusDeEspaco::class);
    }

    /**
     * Roda o trabalho numa transação com a trava da indicação: duas concessões simultâneas se enfileiram e a
     * segunda enxerga a primeira, sem estourar a UNIQUE (que fecharia o EntityManager).
     *
     * @template T
     *
     * @param \Closure(): T $trabalho
     *
     * @return T
     */
    public function emTransacaoTravada(int $indicacaoId, \Closure $trabalho): mixed
    {
        return $this->getEntityManager()->wrapInTransaction(function () use ($indicacaoId, $trabalho): mixed {
            $this->getEntityManager()->getConnection()->executeStatement('SELECT pg_advisory_xact_lock(?, ?)', [self::CLASSE_TRAVA, $indicacaoId]);

            return $trabalho();
        });
    }

    public function jaConcedidoPorIndicacao(int $indicacaoId): bool
    {
        return (bool) $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM contribua_bonus_de_espaco WHERE indicacao_id = ?)',
            [$indicacaoId],
        );
    }

    /** Persiste o bônus da indicação (sem flush: quem fecha é o UseCase). */
    public function novoDaIndicacao(int $tenantId, int $indicacaoId, \DateTimeImmutable $agora): BonusDeEspaco
    {
        $em = $this->getEntityManager();
        $bonus = BonusDeEspaco::daIndicacao($em->getReference(Tenant::class, $tenantId), $em->getReference(Indicacao::class, $indicacaoId), $agora);
        $em->persist($bonus);

        return $bonus;
    }

    /**
     * Os bônus vigentes (`expira_em` maior que `$agora`) de vários escritórios numa consulta só, indexados pelo
     * id do escritório. Quem não tem bônus vigente não aparece.
     *
     * @param list<int> $tenantIds
     *
     * @return array<int, list<BonusDeEspaco>>
     */
    public function vigentesDosTenants(array $tenantIds, \DateTimeImmutable $agora): array
    {
        if ($tenantIds === []) {
            return [];
        }
        $lista = $this->createQueryBuilder('b')
            ->andWhere('IDENTITY(b.tenant) IN (:tenants)')
            ->andWhere('b.expiraEm > :agora')
            ->setParameter('tenants', $tenantIds)
            ->setParameter('agora', $agora, Types::DATETIME_IMMUTABLE)
            ->orderBy('b.expiraEm', 'DESC')
            ->getQuery()
            ->getResult();

        $porTenant = [];
        foreach ($lista as $bonus) {
            $porTenant[(int) $bonus->getTenant()->getId()][] = $bonus;
        }

        return $porTenant;
    }
}
