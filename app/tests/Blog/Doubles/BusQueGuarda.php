<?php

declare(strict_types=1);

namespace App\Tests\Blog\Doubles;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/** Barramento que só guarda as mensagens despachadas, para o teste conferir o que foi pedido ao worker. */
final class BusQueGuarda implements MessageBusInterface
{
    /** @var list<object> */
    public array $mensagens = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->mensagens[] = $message;

        return new Envelope($message, $stamps);
    }
}
