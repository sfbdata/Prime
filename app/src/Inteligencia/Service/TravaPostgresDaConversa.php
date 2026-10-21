<?php

declare(strict_types=1);

namespace App\Inteligencia\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Contract\TravaDaConversa;
use Doctrine\ORM\EntityManagerInterface;

/**
 * `pg_advisory_xact_lock(4716, chave de tenant:pasta:autor)`: mesmo padrão da numeração de pasta (4713) e das
 * travas da Central (4714, 4715). Depende de READ COMMITTED: depois da espera, a checagem "em andamento"
 * enxerga a pergunta que a outra transação gravou.
 *
 * Transação pela CONEXÃO (begin/commit/rollBack), e não `wrapInTransaction`: as recusas esperadas de dentro
 * (limitador, sigilo) fecham o EntityManager no `wrapInTransaction`, e o resto da requisição ainda o usa.
 */
final class TravaPostgresDaConversa implements TravaDaConversa
{
    public const CLASSE_TRAVA = 4716;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function emTransacaoTravada(Tenant $tenant, int $pastaId, User $autor, callable $trabalho): mixed
    {
        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            $conn->executeStatement('SELECT pg_advisory_xact_lock(?, ?)', [self::CLASSE_TRAVA, self::chave($tenant, $pastaId, $autor)]);
            $resultado = $trabalho();
            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollBack();

            throw $e;
        }

        return $resultado;
    }

    /**
     * O segundo argumento é int4: `crc32()` devolve 0..2^32-1 em PHP de 64 bits, a metade alta vira
     * negativa (mesma conta do `SubstituirAnexoDoLoteUseCase`). Colisão só serializa à toa, nunca libera.
     */
    public static function chave(Tenant $tenant, int $pastaId, User $autor): int
    {
        $valor = crc32(sprintf('%d:%d:%d', (int) $tenant->getId(), $pastaId, (int) $autor->getId()));

        return $valor > 2147483647 ? $valor - 4294967296 : $valor;
    }
}
