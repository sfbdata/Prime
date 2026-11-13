<?php

declare(strict_types=1);

namespace App\Voto\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** No terminal ativo, encerrar atendimento impede voltar à cédula anterior pelo histórico do navegador. */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 2)]
final class TerminalDaAssembleiaListener
{
    public function __construct(private readonly UrlGeneratorInterface $rotas)
    {
    }

    public function __invoke(RequestEvent $evento): void
    {
        $request = $evento->getRequest();
        if (!$evento->isMainRequest() || !$request->hasPreviousSession()) {
            return;
        }
        if (!in_array($request->attributes->get('_route'), ['voto_assembleia_cedula', 'voto_assembleia_cedula_votar', 'voto_assembleia_cedula_comprovante_email'], true)) {
            return;
        }
        $codigo = $request->getSession()->get('voto_terminal_codigo');
        if (!\is_string($codigo) || $codigo === '') {
            return;
        }
        $autorizado = $request->getSession()->get('voto_terminal_token_hash', '');
        if (\is_string($autorizado) && hash_equals($autorizado, hash('sha256', $request->attributes->getString('token')))) {
            return;
        }
        $evento->setResponse(new RedirectResponse($this->rotas->generate('voto_assembleia_portal', ['codigo' => $codigo]), 303));
    }
}
