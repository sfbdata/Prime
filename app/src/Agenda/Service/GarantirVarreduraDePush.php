<?php

declare(strict_types=1);

namespace App\Agenda\Service;

use App\Agenda\Contract\EnviadorDePush;
use App\Agenda\Message\VarrerLembretesDePush;
use App\Agenda\Repository\VarreduraDePushRepository;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Põe a cadeia da varredura para andar quando ela não está andando: na primeira inscrição de push, a cada
 * consulta de lembretes de qualquer tela (a cada 5 min por navegador aberto) e pelo comando
 * `app:agenda:push-varredura`. Se o worker ficou parado mais de 3 min, a próxima consulta religa. Semear
 * duas vezes não cria duas cadeias: o handler só deixa seguir quem reivindica a vez.
 */
final class GarantirVarreduraDePush
{
    public function __construct(
        private readonly VarreduraDePushRepository $marca,
        private readonly EnviadorDePush $enviador,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function garantir(): bool
    {
        if (!$this->enviador->ligado() || !$this->marca->estaParada(new \DateTimeImmutable())) {
            return false;
        }
        $this->bus->dispatch(new VarrerLembretesDePush());

        return true;
    }
}
