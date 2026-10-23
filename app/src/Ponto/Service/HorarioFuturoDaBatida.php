<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use App\Entity\Tenant\Tenant;
use Psr\Clock\ClockInterface;

/**
 * Regra única da batida em horário futuro: a data e a hora pedidas, lidas no fuso do escritório,
 * não podem ficar acima de agora. A mesma data e hora de agora passam.
 *
 * Vale no lote do admin, no pedido de esquecimento do colaborador e na aprovação desse pedido.
 */
final class HorarioFuturoDaBatida
{
    public const MENSAGEM = 'Não é possível registrar batida em horário futuro.';

    public function __construct(
        private readonly ClockInterface $relogio,
    ) {
    }

    public function estaNoFuturo(\DateTimeInterface $data, \DateTimeInterface $hora, Tenant $tenant): bool
    {
        $batida = new \DateTimeImmutable(
            $data->format('Y-m-d') . ' ' . $hora->format('H:i:s'),
            FusoDoEscritorio::de($tenant),
        );

        return $batida > $this->relogio->now();
    }
}
