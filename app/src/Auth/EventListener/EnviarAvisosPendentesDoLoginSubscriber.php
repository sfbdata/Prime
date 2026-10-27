<?php

declare(strict_types=1);

namespace App\Auth\EventListener;

use App\Auth\Service\AvisosPendentesDoLogin;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/** Manda o aviso de acesso novo depois que a resposta do login já foi enviada (spec `login-aviso-novo.md`). */
final class EnviarAvisosPendentesDoLoginSubscriber
{
    public function __construct(
        private readonly AvisosPendentesDoLogin $avisos,
    ) {
    }

    #[AsEventListener(event: KernelEvents::TERMINATE)]
    public function aoTerminar(TerminateEvent $event): void
    {
        $this->avisos->enviar();
    }
}
