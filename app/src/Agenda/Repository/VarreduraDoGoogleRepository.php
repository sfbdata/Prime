<?php

declare(strict_types=1);

namespace App\Agenda\Repository;

use Doctrine\DBAL\Connection;

/**
 * Marca da sincronia periódica do Google Agenda (uma linha, id 1, criada pela migration). Mesmo molde do
 * push (VarreduraDePushRepository): só segue quem reivindica a vez num UPDATE atômico.
 */
class VarreduraDoGoogleRepository
{
    /** De quanto em quanto o schedule `cadeias` dispara a sincronia (App\Shared\Scheduler\AgendaDasCadeias). */
    public const INTERVALO_SEGUNDOS = 300;
    /** Janela da vez: metade do intervalo, pelo mesmo motivo do push (VarreduraDePushRepository::VEZ_SEGUNDOS). */
    public const VEZ_SEGUNDOS = 150;

    public function __construct(
        private readonly Connection $conexao,
    ) {
    }

    public function reivindicar(\DateTimeImmutable $agora): bool
    {
        return 1 === $this->conexao->executeStatement(
            'UPDATE agenda_google_varredura SET ultima_em = :agora WHERE id = 1 AND (ultima_em IS NULL OR ultima_em < :limite)',
            ['agora' => $agora->format('Y-m-d H:i:s'), 'limite' => $agora->modify('-' . self::VEZ_SEGUNDOS . ' seconds')->format('Y-m-d H:i:s')],
        );
    }
}
