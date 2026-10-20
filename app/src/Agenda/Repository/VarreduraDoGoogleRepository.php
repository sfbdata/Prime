<?php

declare(strict_types=1);

namespace App\Agenda\Repository;

use Doctrine\DBAL\Connection;

/**
 * Marca da cadeia de sincronia do Google Agenda (uma linha, id 1, criada pela migration). Mesmo molde do
 * push (VarreduraDePushRepository): só segue quem reivindica a vez num UPDATE atômico.
 */
class VarreduraDoGoogleRepository
{
    public const INTERVALO_SEGUNDOS = 300;
    /** Uma cadeia viva marca a cada 5 min; abaixo de 4 min, a mensagem é de outra cadeia e morre. */
    private const VEZ_SEGUNDOS = 240;
    /** Sem marca há mais de 15 min, a cadeia parou (worker parado, mensagem perdida): recomeça. */
    public const PARADA_SEGUNDOS = 900;

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

    public function estaParada(\DateTimeImmutable $agora): bool
    {
        $ultima = $this->conexao->fetchOne('SELECT ultima_em FROM agenda_google_varredura WHERE id = 1');

        return !is_string($ultima) || new \DateTimeImmutable($ultima) < $agora->modify('-' . self::PARADA_SEGUNDOS . ' seconds');
    }
}
