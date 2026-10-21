<?php

declare(strict_types=1);

namespace App\Tarefa\Service;

use Doctrine\ORM\EntityManagerInterface;

/**
 * A sequência de números de meta de um escritório ("Nº 0001", desenho 02 L1559 e L5336): a trava que a
 * serializa, a reserva do próximo número e a numeração das metas antigas.
 *
 * Regras (decisão do dono, R239; PMC-035):
 *  - por ESCRITÓRIO, nunca o id (o id é global e expõe o volume dos outros escritórios);
 *  - nunca reaproveita: o contador (`tarefa_numeracao`) só anda para a frente, então a meta excluída não
 *    devolve o número, nem quando era a última;
 *  - o próximo é o maior entre o contador e o MAX(numero) já gravado, + 1. O MAX entra para o contador
 *    nunca ficar para trás de um número que já existe (escritório sem linha de contador ainda).
 *
 * Mesmo padrão da `NumeracaoDePasta` (trava 4713): `pg_advisory_xact_lock(4717, tenant_id)` só segura até
 * o fim da TRANSAÇÃO, por isso quem chama tem de abrir uma. SQL cru não passa pelo TenantFilter: o
 * `tenant_id = ?` de cada consulta é o isolamento, não é redundante.
 *
 * Recebe o id do escritório, e não a entidade, porque o comando de numeração roda sem sessão e escolhe
 * o escritório explicitamente.
 */
final class NumeracaoDeMetas
{
    /** Espaço de nomes da trava consultiva (4713 pasta, 4714 a 4716 Central e IA). */
    public const CLASSE_TRAVA = 4717;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Serializa quem mexe na sequência deste escritório até o fim da transação.
     *
     * @throws \LogicException se não houver transação aberta (a trava só vale dentro dela)
     */
    public function travar(int $tenantId): void
    {
        $conn = $this->em->getConnection();

        if (!$conn->isTransactionActive()) {
            throw new \LogicException(
                'A numeração de metas exige uma transação aberta: a trava por escritório só segura até o '
                . 'commit. Envolva a operação em wrapInTransaction().'
            );
        }

        $conn->executeStatement('SELECT pg_advisory_xact_lock(?, ?)', [self::CLASSE_TRAVA, $tenantId]);
    }

    /**
     * Reserva `$quantos` números seguidos e devolve o PRIMEIRO. O contador já sai gravado com o último
     * reservado: se a gravação da meta falhar depois, a transação desfaz os dois juntos.
     *
     * Trava antes de ler; chamar duas vezes na mesma transação só reentra na mesma trava.
     */
    public function reservar(int $tenantId, int $quantos = 1): int
    {
        if ($quantos < 1) {
            throw new \InvalidArgumentException('É preciso reservar ao menos um número.');
        }

        $this->travar($tenantId);

        $conn = $this->em->getConnection();
        $base = (int) $conn->fetchOne(
            'SELECT GREATEST(
                COALESCE((SELECT ultimo_numero FROM tarefa_numeracao WHERE tenant_id = ?), 0),
                COALESCE((SELECT MAX(numero) FROM tarefa WHERE tenant_id = ?), 0)
             )',
            [$tenantId, $tenantId]
        );

        $ultimo = $base + $quantos;
        $conn->executeStatement(
            'INSERT INTO tarefa_numeracao (tenant_id, ultimo_numero) VALUES (?, ?)
             ON CONFLICT (tenant_id) DO UPDATE SET ultimo_numero = EXCLUDED.ultimo_numero',
            [$tenantId, $ultimo]
        );

        return $base + 1;
    }

    /**
     * Quantas metas sem número cada escritório tem (o `--dry-run` do comando).
     *
     * @return array<int, int> tenant_id => metas sem número (só escritórios com alguma)
     */
    public function pendentesPorEscritorio(?int $tenantId = null): array
    {
        $sql = 'SELECT tenant_id, COUNT(*) AS total FROM tarefa WHERE numero IS NULL';
        $params = [];
        if ($tenantId !== null) {
            $sql .= ' AND tenant_id = ?';
            $params[] = $tenantId;
        }
        $sql .= ' GROUP BY tenant_id ORDER BY tenant_id';

        $pendentes = [];
        foreach ($this->em->getConnection()->fetchAllAssociative($sql, $params) as $linha) {
            $pendentes[(int) $linha['tenant_id']] = (int) $linha['total'];
        }

        return $pendentes;
    }

    /**
     * Numera as metas sem número de UM escritório, em ordem de criação (data_criacao, depois id), a partir
     * do próximo número livre. Devolve quantas numerou; 0 quando não havia nenhuma (rodar de novo é inócuo).
     *
     * Exige transação aberta, como a reserva: a trava impede que uma meta criada no meio pegue um dos
     * números que esta numeração está distribuindo.
     */
    public function numerarPendentes(int $tenantId): int
    {
        $this->travar($tenantId);

        $conn = $this->em->getConnection();
        $total = (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM tarefa WHERE tenant_id = ? AND numero IS NULL',
            [$tenantId]
        );

        if ($total === 0) {
            return 0;
        }

        $primeiro = $this->reservar($tenantId, $total);

        $conn->executeStatement(
            'UPDATE tarefa AS t
                SET numero = CAST(? AS INTEGER) + s.posicao - 1
               FROM (
                    SELECT id, ROW_NUMBER() OVER (ORDER BY data_criacao, id) AS posicao
                      FROM tarefa
                     WHERE tenant_id = ? AND numero IS NULL
                    ) AS s
              WHERE t.id = s.id AND t.tenant_id = ?',
            [$primeiro, $tenantId, $tenantId]
        );

        return $total;
    }
}
