<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use Psr\Clock\ClockInterface;

/**
 * Regra única da batida em horário futuro: a data e a hora pedidas não podem ficar acima de agora.
 * A mesma data e hora de agora passam.
 *
 * A régua é a da gravação: a batida (`RegistroPonto`) nasce de `new \DateTime(data hora)`, no fuso
 * padrão do servidor, e a batida ao vivo e a tela usam o mesmo relógio. Ler no fuso da sede recusaria,
 * num escritório em Manaus, uma batida que no relógio do servidor já passou.
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

    public function estaNoFuturo(\DateTimeInterface $data, \DateTimeInterface $hora): bool
    {
        $batida = new \DateTimeImmutable(
            $data->format('Y-m-d') . ' ' . $hora->format('H:i:s'),
            new \DateTimeZone(date_default_timezone_get()),
        );

        return $batida > $this->relogio->now();
    }
}
