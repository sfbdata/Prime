<?php

declare(strict_types=1);

namespace App\Agenda\Repository;

use Doctrine\DBAL\Connection;

/**
 * Marca da "última varredura" do push (uma linha, id 1, criada pela migration). Impede duas cadeias de
 * varredura: só segue quem consegue reivindicar a vez num UPDATE atômico.
 */
class VarreduraDePushRepository
{
    public const INTERVALO_SEGUNDOS = 60;
    /** Uma cadeia viva marca a cada 60 s; abaixo disso, a mensagem é de outra cadeia e morre. */
    private const VEZ_SEGUNDOS = 45;
    /** Sem marca há mais que isso, a cadeia parou (worker parado, mensagem perdida): recomeça. */
    public const PARADA_SEGUNDOS = 180;

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

    public function estaParada(\DateTimeImmutable $agora): bool
    {
        $ultima = $this->conexao->fetchOne('SELECT ultima_em FROM agenda_push_varredura WHERE id = 1');

        return !is_string($ultima) || new \DateTimeImmutable($ultima) < $agora->modify('-' . self::PARADA_SEGUNDOS . ' seconds');
    }
}
