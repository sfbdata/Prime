<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use Psr\Clock\ClockInterface;

/**
 * O instante da emissão da folha e do aceite (E16): o relógio do servidor no fuso padrão, sem fração de segundo.
 *
 * Quem grava usa o MESMO objeto para o texto selado e para as colunas (`emitida_em`, `aceito_em`). O DBAL grava a hora
 * do relógio como está, sem converter o fuso, e a coluna não guarda fração: com outro fuso ou com microssegundos, a
 * coluna relida divergiria do texto que o HMAC selou.
 */
final class RelogioDaFolha
{
    public function __construct(
        private readonly ClockInterface $relogio,
    ) {
    }

    public function agora(): \DateTimeImmutable
    {
        $fuso = new \DateTimeZone(date_default_timezone_get());
        $agora = $this->relogio->now()->setTimezone($fuso);

        return $agora->setTime((int) $agora->format('H'), (int) $agora->format('i'), (int) $agora->format('s'));
    }
}
