<?php

declare(strict_types=1);

namespace App\Auth\EventListener;

use App\Auth\DTO\RegistrarAcessoInput;
use App\Auth\UseCase\RegistrarAcessoEAvisarUseCase;
use App\Entity\Auth\User;
use App\Security\FacebookAuthenticator;
use App\Security\GoogleAuthenticator;
use App\Security\UserAuthenticator;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Debug\TraceableAuthenticator;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Aviso de login novo (spec `login-aviso-novo.md`): só o login INTERATIVO conta (formulário, Google, Facebook). O
 * lembrar-me renova uma sessão que a pessoa já abriu e não é "um acesso"; fora da lista nunca entra por engano.
 * Nunca derruba o login.
 */
#[AsEventListener(event: LoginSuccessEvent::class, dispatcher: 'security.event_dispatcher.main')]
final class RegistrarAcessoNoLoginListener
{
    public function __construct(
        private readonly RegistrarAcessoEAvisarUseCase $useCase,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User || !self::interativo($event->getAuthenticator())) {
            return;
        }

        try {
            $request = $event->getRequest();
            $this->useCase->executar(new RegistrarAcessoInput($user, $request->headers->get('User-Agent'), $request->getClientIp()));
        } catch (\Throwable $e) {
            $this->logger->error('Aviso de acesso novo: o listener falhou, o login segue.', ['exception' => $e]);
        }
    }

    private static function interativo(AuthenticatorInterface $autenticador): bool
    {
        if ($autenticador instanceof TraceableAuthenticator) {
            $autenticador = $autenticador->getAuthenticator();
        }

        return $autenticador instanceof UserAuthenticator
            || $autenticador instanceof GoogleAuthenticator
            || $autenticador instanceof FacebookAuthenticator;
    }
}
