<?php

declare(strict_types=1);

namespace App\Security;

use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * No login, grava na sessão as duas marcas que o `ExpiracaoDaSessaoListener` lê (auditoria 3.19 e 3.20):
 *
 *  - o carimbo do último uso = agora. O login migra a sessão e preservaria um carimbo velho de antes de entrar;
 *  - "manter conectado" = o badge do lembrar-me ficou ligado. Decide o servidor, nunca o cookie REMEMBERME que o
 *    cliente pode criar com qualquer valor.
 *
 * Prioridade -40: depois do `CheckRememberMeConditionsListener` (-32), que liga o badge quando a pessoa marcou a caixa.
 */
#[AsEventListener(event: LoginSuccessEvent::class, priority: -40, dispatcher: 'security.event_dispatcher.main')]
final class MarcaDaSessaoNoLoginListener
{
    public function __construct(private readonly ClockInterface $clock)
    {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $request = $event->getRequest();
        if (!$request->hasSession()) {
            return;
        }

        $badge = $event->getPassport()->getBadge(RememberMeBadge::class);
        $sessao = $request->getSession();
        $sessao->set(ExpiracaoDaSessaoListener::CARIMBO, $this->clock->now()->getTimestamp());
        $sessao->set(ExpiracaoDaSessaoListener::MANTER_CONECTADO, $badge instanceof RememberMeBadge && $badge->isEnabled());
    }
}
