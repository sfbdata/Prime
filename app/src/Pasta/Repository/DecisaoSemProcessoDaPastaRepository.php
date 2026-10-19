<?php

declare(strict_types=1);

namespace App\Pasta\Repository;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\DecisaoSemProcessoDaPasta;
use App\Pasta\Entity\Pasta;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DecisaoSemProcessoDaPasta>
 */
class DecisaoSemProcessoDaPastaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DecisaoSemProcessoDaPasta::class);
    }

    public function salvar(DecisaoSemProcessoDaPasta $decisao, bool $flush = false): void
    {
        $this->getEntityManager()->persist($decisao);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Grava a decisão da pasta, sobrescrevendo a anterior, num `INSERT ... ON CONFLICT DO UPDATE`
     * (mesmo motivo do `PastaTimelineVisitaRepository::registrarAbertura`): dois cliques ao mesmo
     * tempo estourariam o UNIQUE no caminho do ORM e fechariam o EntityManager, derrubando junto a
     * marcação de "Administrativo sem processo". Aqui o banco decide a corrida. O `WHERE` do
     * `DO UPDATE` prende a linha ao mesmo escritório.
     *
     * @param list<string> $termos
     */
    public function gravarDecisao(Pasta $pasta, Tenant $tenant, string $decisao, array $termos, ?User $por, \DateTimeImmutable $em): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO pasta_decisao_sem_processo (tenant_id, pasta_id, decisao, termos, por_id, em)
             VALUES (:tenant, :pasta, :decisao, :termos, :por, :em)
             ON CONFLICT (pasta_id) DO UPDATE
                SET decisao = EXCLUDED.decisao, termos = EXCLUDED.termos, por_id = EXCLUDED.por_id, em = EXCLUDED.em
              WHERE pasta_decisao_sem_processo.tenant_id = EXCLUDED.tenant_id',
            [
                'tenant'  => $tenant->getId(),
                'pasta'   => $pasta->getId(),
                'decisao' => $decisao,
                'termos'  => json_encode($termos, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
                'por'     => $por?->getId(),
                'em'      => $em->format('Y-m-d H:i:s'),
            ],
        );
    }

    public function daPasta(Pasta $pasta, Tenant $tenant): ?DecisaoSemProcessoDaPasta
    {
        return $this->findOneBy(['pasta' => $pasta, 'tenant' => $tenant]);
    }

    /**
     * Todas as decisões do escritório, no formato do analisador: o aprendizado (L4515) olha as do
     * escritório inteiro, e a "Decisão anterior" de cada linha sai daqui.
     *
     * @return array<int, array{decisao: string, por: string, termos: list<string>}> pastaId => decisão
     */
    public function doEscritorio(Tenant $tenant): array
    {
        $linhas = $this->createQueryBuilder('d')
            ->select('IDENTITY(d.pasta) AS pasta_id', 'd.decisao', 'd.termos', 'u.fullName', 'u.email')
            ->leftJoin('d.por', 'u')
            ->andWhere('d.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('d.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $porPasta = [];
        foreach ($linhas as $l) {
            $porPasta[(int) $l['pasta_id']] = [
                'decisao' => (string) $l['decisao'],
                'por'     => (string) ($l['fullName'] ?? $l['email'] ?? ''),
                'termos'  => array_values(array_map('strval', (array) $l['termos'])),
            ];
        }

        return $porPasta;
    }
}
