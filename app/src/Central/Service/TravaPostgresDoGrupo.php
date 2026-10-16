<?php

declare(strict_types=1);

namespace App\Central\Service;

use App\Central\Contract\TravaDoGrupo;
use App\Central\Entity\Conversa;
use Doctrine\ORM\EntityManagerInterface;

/**
 * `pg_advisory_xact_lock(4715, id do grupo)` — mesmo padrão de `TravaPostgresDosAvisosDeMencao` (4714) e da
 * numeração de pasta (4713). Depende de READ COMMITTED: a checagem depois da espera enxerga o commit da outra.
 */
final class TravaPostgresDoGrupo implements TravaDoGrupo
{
    public const CLASSE_TRAVA = 4715;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function travar(Conversa $grupo): void
    {
        $conn = $this->em->getConnection();

        if (!$conn->isTransactionActive()) {
            throw new \LogicException('Mudar a participação do grupo exige transação aberta (wrapInTransaction).');
        }

        $conn->executeStatement('SELECT pg_advisory_xact_lock(?, ?)', [self::CLASSE_TRAVA, (int) $grupo->getId()]);
    }
}
