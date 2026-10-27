<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\Repository\EventoDeDesempenhoRepository;
use Psr\Clock\ClockInterface;

/**
 * Retenção dos eventos do monitor (spec master-desempenho §2.1): apaga os de mais de 90 dias.
 */
final class PurgarEventosDeDesempenhoUseCase
{
    public const RETENCAO_EM_DIAS = 90;

    public function __construct(
        private readonly EventoDeDesempenhoRepository $eventos,
        private readonly ClockInterface $clock,
    ) {
    }

    /** @return int quantos eventos foram apagados */
    public function executar(): int
    {
        $limite = \DateTimeImmutable::createFromInterface($this->clock->now())->modify('-' . self::RETENCAO_EM_DIAS . ' days');

        return $this->eventos->apagarAntesDe($limite);
    }
}
