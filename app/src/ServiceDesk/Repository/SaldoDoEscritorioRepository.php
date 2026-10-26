<?php

declare(strict_types=1);

namespace App\ServiceDesk\Repository;

use App\Entity\Tenant\Tenant;
use App\ServiceDesk\Entity\SaldoDoEscritorio;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SaldoDoEscritorio>
 */
class SaldoDoEscritorioRepository extends ServiceEntityRepository
{
    use SemFiltroDeTenant;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SaldoDoEscritorio::class);
    }

    public function salvar(SaldoDoEscritorio $saldo, bool $flush = false): void
    {
        $this->getEntityManager()->persist($saldo);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /** Saldo sem travar, para leitura (tela). Escritório sem movimento ainda devolve zero, sem gravar. */
    public function doEscritorio(Tenant $tenant): SaldoDoEscritorio
    {
        return $this->findOneBy(['tenant' => $tenant]) ?? new SaldoDoEscritorio($tenant);
    }

    /**
     * Saldo com a linha travada até o COMMIT (use dentro de `TransacaoDoRazao::emTransacao`).
     * Primeira vez do escritório: `INSERT ... ON CONFLICT DO NOTHING` garante a linha sem que dois
     * "primeiros" concorrentes briguem (e sem travar a linha do tenant), e só então a trava.
     */
    public function travarDoEscritorio(Tenant $tenant): SaldoDoEscritorio
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO service_desk_saldo (tenant_id, saldo_centavos, atualizado_em) VALUES (?, 0, NOW()) ON CONFLICT (tenant_id) DO NOTHING',
            [$tenant->getId()],
        );

        return $this->semFiltroDeTenant(fn (): SaldoDoEscritorio => $this->createQueryBuilder('s')
            ->andWhere('s.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getSingleResult());
    }
}
