<?php

declare(strict_types=1);

namespace App\Master\EventListener;

use App\Entity\Auth\User;
use App\Master\UseCase\RegistrarAcessoDoDiaUseCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Linha "Acessos ao sistema" do gráfico Crescimento (desenho 05 - Perfis 1.2, VS 03.11.26): cada login
 * bem-sucedido marca o dia da pessoa (uma linha por pessoa por dia). Só observa: não muda o fluxo de
 * autenticação, e a falha ao gravar vai para o log e é engolida; o login nunca cai por causa disto.
 */
#[AsEventListener(event: LoginSuccessEvent::class, dispatcher: 'security.event_dispatcher.main')]
final class AcessoDiarioNoLoginListener
{
    public function __construct(
        private readonly RegistrarAcessoDoDiaUseCase $useCase,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        try {
            $this->useCase->executar($user);
        } catch (\Throwable $e) {
            $this->logger->warning('Acessos por dia: não gravou o acesso, o login segue.', ['erro' => $e->getMessage()]);
        }
    }
}
