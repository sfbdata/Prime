<?php

declare(strict_types=1);

namespace App\Agenda\MessageHandler;

use App\Agenda\Contract\EnviadorDePush;
use App\Agenda\Message\VarrerLembretesDePush;
use App\Agenda\Repository\VarreduraDePushRepository;
use App\Agenda\UseCase\VarrerLembretesDePushUseCase;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * A cadeia da varredura: reivindica a vez na marca do banco (UPDATE atômico), varre e agenda a próxima
 * volta. Mensagem que não consegue a vez é de uma cadeia duplicada (deploy repetido, dois workers, partida
 * semeada duas vezes) e morre sem se reagendar. Com o push desligado (sem chaves VAPID), a cadeia para.
 */
#[AsMessageHandler]
final class VarrerLembretesDePushHandler
{
    public function __construct(
        private readonly VarreduraDePushRepository $marca,
        private readonly VarrerLembretesDePushUseCase $varrer,
        private readonly EnviadorDePush $enviador,
        private readonly MessageBusInterface $bus,
    ) {
    }

    public function __invoke(VarrerLembretesDePush $mensagem): void
    {
        $agora = new \DateTimeImmutable();
        if (!$this->enviador->ligado() || !$this->marca->reivindicar($agora)) {
            return;
        }
        try {
            $this->varrer->executar($agora);
        } finally {
            $this->bus->dispatch(new VarrerLembretesDePush(), [new DelayStamp(VarreduraDePushRepository::INTERVALO_SEGUNDOS * 1000)]);
        }
    }
}
