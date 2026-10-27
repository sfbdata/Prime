<?php

declare(strict_types=1);

namespace App\Master\EventListener;

use App\Entity\Auth\User;
use App\Master\DTO\RegistrarEventoDeDesempenhoInput;
use App\Master\Enum\TipoDeEventoDeDesempenho;
use App\Master\UseCase\RegistrarEventoDeDesempenhoUseCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Alimenta o monitor de saúde do Master com cada tentativa de entrada (spec docs/specs/master-desempenho.md, D2).
 * Dado pessoal mínimo (§2.1): o sucesso leva o `usuario`; a falha entra SEM usuário, sem e-mail digitado e sem IP.
 * O monitor nunca atrapalha o login: erro ao gravar é registrado no log e engolido.
 */
final class EventoDeLoginNoMonitorListener
{
    public function __construct(
        private readonly RegistrarEventoDeDesempenhoUseCase $useCase,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsEventListener(event: LoginSuccessEvent::class, dispatcher: 'security.event_dispatcher.main')]
    public function aoEntrar(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        $this->registrar(true, $user instanceof User ? $user : null);
    }

    #[AsEventListener(event: LoginFailureEvent::class, dispatcher: 'security.event_dispatcher.main')]
    public function aoFalhar(LoginFailureEvent $event): void
    {
        $this->registrar(false, null);
    }

    private function registrar(bool $ok, ?User $usuario): void
    {
        try {
            $this->useCase->executar(new RegistrarEventoDeDesempenhoInput(TipoDeEventoDeDesempenho::Login, $usuario, $ok));
        } catch (\Throwable $e) {
            $this->logger->warning('Monitor de desempenho: não gravou o evento de login.', ['erro' => $e->getMessage()]);
        }
    }
}
