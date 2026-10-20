<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\AuthenticationTrustResolverInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AccountStatusException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\LazyResponseException;
use Symfony\Component\Security\Core\Exception\LogoutException;

/**
 * Pedido de dados sem sessão recebe 401 em JSON ANTES do firewall, para não tocar o destino do login.
 *
 * O `ExceptionListener` do firewall (prioridade 1, registrado por pedido em
 * `Symfony\Component\Security\Http\Firewall\ExceptionListener::register()`) grava o `_security.main.target_path`
 * antes de chamar o `UserAuthenticator::start()`, e só pula XHR com `X-Requested-With`. Um `fetch` comum gravava
 * o destino (e o login caía no JSON); apagar o destino depois, no `start()`, destruía o que outra aba de
 * navegação tinha gravado. Com prioridade 2, este listener responde primeiro, o `setResponse()` para a
 * propagação e o firewall nem roda.
 *
 * Age só no firewall `main` e só nos casos em que o firewall chamaria o login: `AuthenticationException`, ou
 * `AccessDeniedException` sem autenticação completa (o mesmo `isFullFledged` do firewall). Usuário logado sem
 * permissão continua recebendo 403 pelo caminho de sempre.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 2)]
final class SessaoVencidaEmPedidoDeDadosListener
{
    private const FIREWALL = 'main';

    public function __construct(
        private readonly Security $security,
        private readonly TokenStorageInterface $tokenStorage,
        #[Autowire(service: 'security.authentication.trust_resolver')]
        private readonly AuthenticationTrustResolverInterface $trustResolver,
    ) {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        $request = $event->getRequest();

        if (PedidoDeNavegacao::eNavegacao($request) || $this->security->getFirewallConfig($request)?->getName() !== self::FIREWALL) {
            return;
        }

        $excecao = $this->excecaoQueLevariaAoLogin($event->getThrowable());
        if ($excecao === null) {
            return;
        }

        if ($excecao instanceof AccountStatusException) {
            // O mesmo que o firewall faz antes do login: sem isso, a conta bloqueada entraria em laço.
            $this->tokenStorage->setToken(null);
        }

        $event->setResponse(PedidoDeNavegacao::respostaDeSessaoVencida());
        $event->allowCustomResponseCode();
    }

    /**
     * Percorre a cadeia como o `ExceptionListener::onKernelException()` do firewall: a primeira exceção de
     * segurança decide.
     */
    private function excecaoQueLevariaAoLogin(\Throwable $excecao): ?\Throwable
    {
        do {
            if ($excecao instanceof AuthenticationException) {
                return $excecao;
            }

            if ($excecao instanceof AccessDeniedException) {
                return $this->trustResolver->isFullFledged($this->tokenStorage->getToken()) ? null : $excecao;
            }

            if ($excecao instanceof LazyResponseException || $excecao instanceof LogoutException) {
                return null;
            }
        } while (null !== $excecao = $excecao->getPrevious());

        return null;
    }
}
