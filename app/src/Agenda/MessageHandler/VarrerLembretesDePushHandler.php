<?php

declare(strict_types=1);

namespace App\Agenda\MessageHandler;

use App\Agenda\Contract\EnviadorDePush;
use App\Agenda\Message\VarrerLembretesDePush;
use App\Agenda\Repository\VarreduraDePushRepository;
use App\Agenda\UseCase\VarrerLembretesDePushUseCase;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Uma volta da varredura do push. Quem a dispara, a cada minuto, é o schedule `cadeias`
 * ({@see \App\Shared\Scheduler\AgendaDasCadeias}); o handler não se reagenda. Reivindica a vez na marca do banco
 * (UPDATE atômico): uma volta a mais dentro da janela (mensagem antiga da cadeia de antes, dois workers) não varre de
 * novo. Com o push desligado (sem chaves VAPID), não faz nada.
 */
#[AsMessageHandler]
final class VarrerLembretesDePushHandler
{
    public function __construct(
        private readonly VarreduraDePushRepository $marca,
        private readonly VarrerLembretesDePushUseCase $varrer,
        private readonly EnviadorDePush $enviador,
    ) {
    }

    public function __invoke(VarrerLembretesDePush $mensagem): void
    {
        $agora = new \DateTimeImmutable();
        if (!$this->enviador->ligado() || !$this->marca->reivindicar($agora)) {
            return;
        }
        $this->varrer->executar($agora);
    }
}
