<?php

declare(strict_types=1);

namespace App\Dashboard\Repository;

use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Base do "1º do mês" do cartão do colaborador (spec dashboard-parte-1, item 4): Total metas e
 * Metas ativas por responsável em CADA mês, com a régua do painel (meta entra pelo mês de
 * `data_criacao`; ativa = status diferente de concluída, lido hoje). Uma consulta agregada para
 * todo o histórico do escritório, sem laço por mês.
 *
 * SQL nativo porque o DQL do projeto não tem função de ano/mês. O escopo é explícito: tenant da
 * meta E da pasta, pasta excluída (lápide) fora e pastas ocultas pelo cadeado para quem olha fora,
 * como em TarefaRepository::countPorResponsavel.
 */
// Não-final: permite substituição por mock nos testes de UseCase (como DashboardFotoRepository).
class HistoricoDeMetasDoDashboardRepository
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param list<int> $pastasOcultas
     *
     * @return list<array{ano: int, mes: int, userId: int, total: int, ativas: int}>
     */
    public function contarPorMesEResponsavel(Tenant $tenant, array $pastasOcultas = []): array
    {
        $sql = 'SELECT CAST(EXTRACT(YEAR FROM t.data_criacao) AS INT) AS ano,
                       CAST(EXTRACT(MONTH FROM t.data_criacao) AS INT) AS mes,
                       r.user_id, COUNT(t.id) AS total,
                       SUM(CASE WHEN t.status <> :concluida THEN 1 ELSE 0 END) AS ativas
                  FROM tarefa t
                  JOIN pasta p ON p.id = t.pasta_id
                  JOIN tarefa_responsaveis r ON r.tarefa_id = t.id
                 WHERE t.tenant_id = :tenant AND p.tenant_id = :tenant AND p.excluida_em IS NULL'
            . ($pastasOcultas !== [] ? ' AND t.pasta_id NOT IN (:ocultas)' : '')
            . ' GROUP BY 1, 2, 3';

        $parametros = ['concluida' => Tarefa::STATUS_CONCLUIDA, 'tenant' => (int) $tenant->getId()];
        $tipos      = [];
        if ($pastasOcultas !== []) {
            $parametros['ocultas'] = $pastasOcultas;
            $tipos['ocultas']      = ArrayParameterType::INTEGER;
        }
        $linhas = $this->em->getConnection()->fetchAllAssociative($sql, $parametros, $tipos);

        return array_map(static fn (array $l): array => [
            'ano'    => (int) $l['ano'],
            'mes'    => (int) $l['mes'],
            'userId' => (int) $l['user_id'],
            'total'  => (int) $l['total'],
            'ativas' => (int) $l['ativas'],
        ], $linhas);
    }
}
