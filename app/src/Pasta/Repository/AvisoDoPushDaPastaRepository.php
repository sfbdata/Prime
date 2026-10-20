<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\AvisoDoPushDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\PadroesDoAvisoDoPush;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * As chaves do "Avisar quando chegar" por pasta (spec push-avisar-quando-chegar §1). Toda consulta
 * leva o tenant explícito: a captação roda no CLI, sem TenantFilter.
 *
 * @extends ServiceEntityRepository<AvisoDoPushDaPasta>
 */
class AvisoDoPushDaPastaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AvisoDoPushDaPasta::class);
    }

    public function salvar(AvisoDoPushDaPasta $aviso, bool $flush = false): void
    {
        $this->getEntityManager()->persist($aviso);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function daPastaDoTenant(Pasta $pasta, Tenant $tenant): ?AvisoDoPushDaPasta
    {
        return $this->findOneBy(['pasta' => $pasta, 'tenant' => $tenant]);
    }

    /**
     * A linha da pasta, criada com os padrões se ainda não existe. O INSERT é `ON CONFLICT (pasta_id)
     * DO NOTHING`: na corrida da primeira gravação (duas abas, dois cliques) o segundo não estoura o
     * UNIQUE (o que fecharia o EntityManager e viraria 500); os dois releem a mesma linha e seguem.
     */
    public function garantirDaPasta(Pasta $pasta, Tenant $tenant, User $por, \DateTimeImmutable $em): AvisoDoPushDaPasta
    {
        $existente = $this->daPastaDoTenant($pasta, $tenant);
        if ($existente !== null) {
            return $existente;
        }

        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO pasta_aviso_push (tenant_id, pasta_id, avisar_responsavel, por_email, analise_ia, atualizado_por_id, atualizado_em)
             VALUES (:tenant, :pasta, :responsavel, :email, :ia, :por, :em)
             ON CONFLICT (pasta_id) DO NOTHING',
            [
                'tenant' => $tenant->getId(), 'pasta' => $pasta->getId(), 'por' => $por->getId(), 'em' => $em->format('Y-m-d H:i:s'),
                'responsavel' => PadroesDoAvisoDoPush::RESPONSAVEL, 'email' => PadroesDoAvisoDoPush::EMAIL, 'ia' => PadroesDoAvisoDoPush::IA,
            ],
            ['responsavel' => 'boolean', 'email' => 'boolean', 'ia' => 'boolean'],
        );
        $linha = $this->daPastaDoTenant($pasta, $tenant);
        \assert($linha instanceof AvisoDoPushDaPasta);

        return $linha;
    }

    /**
     * As linhas das pastas informadas, por id da pasta (pasta sem linha fica de fora: usa os padrões).
     *
     * @param list<int> $pastaIds
     *
     * @return array<int, AvisoDoPushDaPasta>
     */
    public function porPastasDoTenant(Tenant $tenant, array $pastaIds): array
    {
        if ($pastaIds === []) {
            return [];
        }

        /** @var list<AvisoDoPushDaPasta> $linhas */
        $linhas = $this->createQueryBuilder('a')
            ->andWhere('a.tenant = :tenant')
            ->andWhere('IDENTITY(a.pasta) IN (:pastas)')
            ->setParameter('tenant', $tenant)
            ->setParameter('pastas', $pastaIds)
            ->getQuery()
            ->getResult();

        $porPasta = [];
        foreach ($linhas as $linha) {
            $porPasta[(int) $linha->getPasta()->getId()] = $linha;
        }

        return $porPasta;
    }
}
