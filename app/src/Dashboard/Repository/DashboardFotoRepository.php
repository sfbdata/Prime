<?php

declare(strict_types=1);

namespace App\Dashboard\Repository;

use App\Dashboard\Entity\DashboardFoto;
use App\Entity\Tenant\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
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
    /** Upsert de UMA foto: o UNIQUE (tenant_id, user_id, referencia) é o árbitro. */
    private const SQL_UPSERT = 'INSERT INTO dashboard_foto
                        (tenant_id, user_id, referencia, metas_ativas, demandas_ativas, metas_vencidas, prazos_proximos, metas_em_revisao, pastas_urgentes, metas_total, criado_em)
                     VALUES (:tenant, :usuario, :referencia, :metas_ativas, :demandas_ativas, :metas_vencidas, :prazos_proximos, :metas_em_revisao, :pastas_urgentes, :metas_total, :criado_em)
                     ON CONFLICT (tenant_id, user_id, referencia) DO UPDATE SET
                        metas_ativas    = EXCLUDED.metas_ativas,
                        demandas_ativas = EXCLUDED.demandas_ativas,
                        metas_vencidas  = EXCLUDED.metas_vencidas,
                        prazos_proximos = EXCLUDED.prazos_proximos,
                        metas_em_revisao = EXCLUDED.metas_em_revisao,
                        pastas_urgentes  = EXCLUDED.pastas_urgentes,
                        metas_total      = EXCLUDED.metas_total,
                        criado_em       = EXCLUDED.criado_em';

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
     * @param array<int, array{metas_ativas: int, demandas_ativas: int, metas_vencidas: int, prazos_proximos: int, metas_em_revisao?: int|null, pastas_urgentes?: int|null, metas_total?: int|null}> $contagens userId => números
     *                 (as três últimas são o lastro do PR-F; ausente ou null grava NULL, nunca zero)
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

        return (int) $conexao->transactional(function (Connection $conexao) use ($contagens, $criadoEm, $dia, $tenantId): int {
            $gravadas = 0;
            foreach ($contagens as $userId => $numeros) {
                $gravadas += (int) $conexao->executeStatement(self::SQL_UPSERT, $this->parametrosDaFoto($tenantId, (int) $userId, $dia, $criadoEm, $numeros));
            }

            return $gravadas;
        });
    }

    /**
     * Os parâmetros do upsert. O lastro do PR-F (`metas_em_revisao`, `pastas_urgentes`, `metas_total`)
     * ausente ou null vira NULL, nunca zero.
     *
     * @param array<string, int|null> $numeros
     *
     * @return array<string, int|string|null>
     */
    private function parametrosDaFoto(int $tenantId, int $userId, string $dia, string $criadoEm, array $numeros): array
    {
        return [
            'tenant'           => $tenantId,
            'usuario'          => $userId,
            'referencia'       => $dia,
            'metas_ativas'     => (int) $numeros['metas_ativas'],
            'demandas_ativas'  => (int) $numeros['demandas_ativas'],
            'metas_vencidas'   => (int) $numeros['metas_vencidas'],
            'prazos_proximos'  => (int) $numeros['prazos_proximos'],
            'metas_em_revisao' => isset($numeros['metas_em_revisao']) ? (int) $numeros['metas_em_revisao'] : null,
            'pastas_urgentes'  => isset($numeros['pastas_urgentes']) ? (int) $numeros['pastas_urgentes'] : null,
            'metas_total'      => isset($numeros['metas_total']) ? (int) $numeros['metas_total'] : null,
            'criado_em'        => $criadoEm,
        ];
    }

    /**
     * Fotos do dia `referencia` para os colaboradores pedidos, só deste escritório. Quem não foi
     * fotografado naquele dia simplesmente não aparece no mapa — o chamador decide o que isso
     * significa (no Dashboard: sem tendência, nunca zero inventado).
     *
     * @param int[] $userIds
     *
     * As colunas do PR-F (`metas_em_revisao`, `pastas_urgentes`, `metas_total`) voltam null quando a
     * foto é anterior a elas: sem lastro, o chamador esconde a seta.
     *
     * @return array<int, array{metas_ativas: int, demandas_ativas: int, metas_vencidas: int, prazos_proximos: int, metas_em_revisao: int|null, pastas_urgentes: int|null, metas_total: int|null}> userId => números
     */
    public function buscarPorReferencia(Tenant $tenant, \DateTimeImmutable $referencia, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('f')
            ->select('IDENTITY(f.usuario) AS user_id, f.metasAtivas, f.demandasAtivas, f.metasVencidas, f.prazosProximos, f.metasEmRevisao, f.pastasUrgentes, f.metasTotal')
            ->andWhere('f.tenant = :tenant')
            ->andWhere('f.referencia = :referencia')
            ->andWhere('f.usuario IN (:usuarios)')
            ->setParameter('tenant', $tenant)
            ->setParameter('referencia', $referencia->format('Y-m-d'))
            ->setParameter('usuarios', array_map('intval', $userIds))
            ->getQuery()
            ->getArrayResult();

        $mapa = [];
        foreach ($rows as $row) {
            $mapa[(int) $row['user_id']] = [
                'metas_ativas'    => (int) $row['metasAtivas'],
                'demandas_ativas' => (int) $row['demandasAtivas'],
                'metas_vencidas'  => (int) $row['metasVencidas'],
                'prazos_proximos' => (int) $row['prazosProximos'],
                'metas_em_revisao' => $row['metasEmRevisao'] === null ? null : (int) $row['metasEmRevisao'],
                'pastas_urgentes'  => $row['pastasUrgentes'] === null ? null : (int) $row['pastasUrgentes'],
                'metas_total'      => $row['metasTotal'] === null ? null : (int) $row['metasTotal'],
            ];
        }

        return $mapa;
    }

    /**
     * Retrato de cada mês para o histórico dos cards: por mês, a SOMA da coluna (e das metas ativas)
     * sobre as fotos do ÚLTIMO DIA do mês que tem foto com a coluna preenchida, só dos colaboradores
     * pedidos. Mês sem foto (ou só com foto antiga, coluna NULL) não aparece no mapa: sem lastro, e o
     * chamador mostra "sem histórico", nunca zero. Escopo explícito do tenant.
     *
     * Metas com mais de um responsável contam para cada um (a soma das linhas, como o Total da tabela).
     *
     * @param string    $coluna  metas_ativas | pastas_urgentes | metas_total
     * @param list<int> $userIds
     *
     * @return array<string, array{valor: int, ativas: int}> 'YYYY-MM' => soma da coluna e das metas ativas
     */
    public function retratoPorMes(Tenant $tenant, array $userIds, string $coluna, \DateTimeImmutable $de, \DateTimeImmutable $ate): array
    {
        if ($userIds === [] || !\in_array($coluna, ['metas_ativas', 'pastas_urgentes', 'metas_total'], true)) {
            return [];
        }

        $sql = 'SELECT ano_mes, SUM(valor_foto) AS valor, SUM(ativas_foto) AS ativas FROM (
                    SELECT TO_CHAR(referencia, \'YYYY-MM\') AS ano_mes, ' . $coluna . ' AS valor_foto, metas_ativas AS ativas_foto,
                           RANK() OVER (PARTITION BY TO_CHAR(referencia, \'YYYY-MM\') ORDER BY referencia DESC) AS posicao
                      FROM dashboard_foto
                     WHERE tenant_id = :tenant AND user_id IN (:usuarios) AND ' . $coluna . ' IS NOT NULL
                       AND referencia BETWEEN :de AND :ate
                ) f WHERE posicao = 1 GROUP BY ano_mes';

        $linhas = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            $sql,
            ['tenant' => (int) $tenant->getId(), 'usuarios' => array_map('intval', $userIds), 'de' => $de->format('Y-m-d'), 'ate' => $ate->format('Y-m-d')],
            ['usuarios' => ArrayParameterType::INTEGER],
        );

        $mapa = [];
        foreach ($linhas as $linha) {
            $mapa[(string) $linha['ano_mes']] = ['valor' => (int) $linha['valor'], 'ativas' => (int) $linha['ativas']];
        }

        return $mapa;
    }
}
