<?php

declare(strict_types=1);

namespace App\Dashboard\Repository;

use App\Dashboard\Entity\DashboardFoto;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Fotos diárias do estoque do Dashboard. Toda consulta e toda escrita recebe o Tenant
 * EXPLICITAMENTE: o comando que fotografa roda no console, onde o TenantFilter fica inerte,
 * e quem segura o isolamento é este repositório.
 *
 * @extends ServiceEntityRepository<DashboardFoto>
 */
// Não-final: permite substituição por mock nos testes de UseCase (como PastaRepository).
class DashboardFotoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DashboardFoto::class);
    }

    /**
     * Grava (ou regrava) a foto de cada colaborador no dia `referencia`, de forma IDEMPOTENTE:
     * rodar o mesmo dia de novo sobrescreve os números em vez de duplicar a linha.
     *
     * SQL direto com `ON CONFLICT DO UPDATE` (PostgreSQL), como o `PastaFavoritaRepository`: no
     * caminho do ORM uma corrida (dois crons sobrepostos) estouraria a unique e fecharia o
     * EntityManager. O UNIQUE (tenant_id, user_id, referencia) é o árbitro. Tudo numa transação:
     * ou o escritório inteiro fica fotografado naquele dia, ou nada.
     *
     * @param array<int, array{metas_ativas: int, demandas_ativas: int, metas_vencidas: int, prazos_proximos: int}> $contagens userId => números
     *
     * @return int linhas gravadas (inseridas ou atualizadas)
     */
    public function gravar(Tenant $tenant, \DateTimeImmutable $referencia, array $contagens): int
    {
        if ($contagens === []) {
            return 0;
        }

        $conexao  = $this->getEntityManager()->getConnection();
        $criadoEm = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $dia      = $referencia->format('Y-m-d');
        $tenantId = $tenant->getId();

        return (int) $conexao->transactional(static function (Connection $conexao) use ($contagens, $criadoEm, $dia, $tenantId): int {
            $gravadas = 0;
            foreach ($contagens as $userId => $numeros) {
                $gravadas += (int) $conexao->executeStatement(
                    'INSERT INTO dashboard_foto
                        (tenant_id, user_id, referencia, metas_ativas, demandas_ativas, metas_vencidas, prazos_proximos, criado_em)
                     VALUES (:tenant, :usuario, :referencia, :metas_ativas, :demandas_ativas, :metas_vencidas, :prazos_proximos, :criado_em)
                     ON CONFLICT (tenant_id, user_id, referencia) DO UPDATE SET
                        metas_ativas    = EXCLUDED.metas_ativas,
                        demandas_ativas = EXCLUDED.demandas_ativas,
                        metas_vencidas  = EXCLUDED.metas_vencidas,
                        prazos_proximos = EXCLUDED.prazos_proximos,
                        criado_em       = EXCLUDED.criado_em',
                    [
                        'tenant'          => $tenantId,
                        'usuario'         => (int) $userId,
                        'referencia'      => $dia,
                        'metas_ativas'    => (int) $numeros['metas_ativas'],
                        'demandas_ativas' => (int) $numeros['demandas_ativas'],
                        'metas_vencidas'  => (int) $numeros['metas_vencidas'],
                        'prazos_proximos' => (int) $numeros['prazos_proximos'],
                        'criado_em'       => $criadoEm,
                    ],
                );
            }

            return $gravadas;
        });
    }

    /**
     * Fotos do dia `referencia` para os colaboradores pedidos, só deste escritório. Quem não foi
     * fotografado naquele dia simplesmente não aparece no mapa — o chamador decide o que isso
     * significa (no Dashboard: sem tendência, nunca zero inventado).
     *
     * @param int[] $userIds
     *
     * @return array<int, array{metas_ativas: int, demandas_ativas: int, metas_vencidas: int, prazos_proximos: int}> userId => números
     */
    public function buscarPorReferencia(Tenant $tenant, \DateTimeImmutable $referencia, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('f')
            ->select('IDENTITY(f.usuario) AS user_id, f.metasAtivas, f.demandasAtivas, f.metasVencidas, f.prazosProximos')
            ->andWhere('f.tenant = :tenant')
            ->andWhere('f.referencia = :referencia')
            ->andWhere('f.usuario IN (:usuarios)')
            ->setParameter('tenant', $tenant)
            ->setParameter('referencia', $referencia->format('Y-m-d'))
            ->setParameter('usuarios', array_values(array_map('intval', $userIds)))
            ->getQuery()
            ->getArrayResult();

        $mapa = [];
        foreach ($rows as $row) {
            $mapa[(int) $row['user_id']] = [
                'metas_ativas'    => (int) $row['metasAtivas'],
                'demandas_ativas' => (int) $row['demandasAtivas'],
                'metas_vencidas'  => (int) $row['metasVencidas'],
                'prazos_proximos' => (int) $row['prazosProximos'],
            ];
        }

        return $mapa;
    }
}
