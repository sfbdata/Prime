<?php

declare(strict_types=1);

namespace App\Central\Service;

use App\Central\Contract\TravaDosAvisosDeMencao;
use App\Entity\Auth\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * `pg_advisory_xact_lock(classe, id do destinatário)` — o mesmo padrão da `NumeracaoDePasta`.
 *
 * A trava só é liberada no fim da TRANSAÇÃO; sem transação aberta ela cairia no mesmo instante e a corrida
 * voltaria calada (dois avisos). Por isso recusa trabalhar fora de `wrapInTransaction`. A chave é só o id da
 * pessoa: duas conversas avisando a mesma pessoa esperam uma pela outra por milissegundos, o que é inofensivo,
 * e não há colisão de chave entre pessoas.
 */
final class TravaPostgresDosAvisosDeMencao implements TravaDosAvisosDeMencao
{
    /** Classe da trava — distinta da numeração de pasta (4713). */
    public const CLASSE_TRAVA = 4714;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function travar(User $destinatario): void
    {
        $conn = $this->em->getConnection();

        if (!$conn->isTransactionActive()) {
            throw new \LogicException(
                'O aviso de menção exige uma transação aberta: a trava por destinatário só segura até o commit. '
                . 'Envolva a operação em wrapInTransaction().'
            );
        }

        $conn->executeStatement('SELECT pg_advisory_xact_lock(?, ?)', [self::CLASSE_TRAVA, (int) $destinatario->getId()]);
    }
}
