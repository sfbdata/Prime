<?php

declare(strict_types=1);

namespace App\Agenda\Repository;

use Doctrine\DBAL\Connection;

/**
 * Marca da "última varredura" do push (uma linha, id 1, criada pela migration). Segunda proteção depois da trava do
 * schedule `cadeias`: só varre quem consegue reivindicar a vez num UPDATE atômico.
 */
class VarreduraDePushRepository
{
    /** De quanto em quanto o schedule `cadeias` dispara a varredura (App\Shared\Scheduler\AgendaDasCadeias). */
    public const INTERVALO_SEGUNDOS = 60;
    /**
     * Janela da vez: metade do intervalo. Barra a volta repetida (mensagem antiga da cadeia de antes, dois workers),
     * que chega segundos depois, e deixa passar a do minuto seguinte mesmo que o worker a pegue atrasada ou o relógio
     * do Scheduler e o do redispatch se desencontrem em alguns segundos.
     */
    public const VEZ_SEGUNDOS = 30;

    public function __construct(
        private readonly Connection $conexao,
    ) {
    }

    public function reivindicar(\DateTimeImmutable $agora): bool
    {
        return 1 === $this->conexao->executeStatement(
            'UPDATE agenda_push_varredura SET ultima_em = :agora WHERE id = 1 AND (ultima_em IS NULL OR ultima_em < :limite)',
            ['agora' => $agora->format('Y-m-d H:i:s'), 'limite' => $agora->modify('-' . self::VEZ_SEGUNDOS . ' seconds')->format('Y-m-d H:i:s')],
        );
    }
}
