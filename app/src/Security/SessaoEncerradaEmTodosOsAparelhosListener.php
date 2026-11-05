<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Auth\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Sair de todos os aparelhos": a sessão que entrou antes do corte do usuário (`User::getSessoesEncerradasEm()`) é
 * encerrada no pedido seguinte dela. As sessões ficam em arquivo, sem lista por usuário: por isso o corte fica no
 * usuário (que o firewall relê do banco a cada pedido) e a sessão guarda a hora em que entrou (`ENTROU_EM`, gravada
 * no login pelo `MarcaDaSessaoNoLoginListener`). Sessão sem a marca (aberta antes desta versão) conta como antiga.
 * Quem entrou no mesmo segundo do corte também cai: a marca e o corte têm só segundos, e na dúvida o lado seguro é
 * pedir para entrar de novo. A sessão de quem apertou o botão fica marcada um segundo depois (ProfileController).
 *
 * Roda depois do firewall (prioridade 8; aqui 7, como a expiração). Responde como a expiração: navegação vai ao
 * /login com o aviso; pedido de dados recebe 401 em JSON.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
final class SessaoEncerradaEmTodosOsAparelhosListener
{
    /** Instante (segundos Unix) em que esta sessão entrou. */
    public const ENTROU_EM = '_bj_entrou_em';

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->hasPreviousSession()) {
            return;
        }
        $user = $this->tokenStorage->getToken()?->getUser();
        $corte = $user instanceof User ? $user->getSessoesEncerradasEm() : null;
        if ($corte === null) {
            return;
        }
        $sessao = $request->getSession();
        $entrouEm = $sessao->get(self::ENTROU_EM);
        if (is_int($entrouEm) && $entrouEm > $corte->getTimestamp()) {
            return;
        }

        $this->tokenStorage->setToken(null);
        $sessao->invalidate();
        if (!PedidoDeNavegacao::eNavegacao($request)) {
            $event->setResponse(new JsonResponse(['erro' => PedidoDeNavegacao::MENSAGEM_SESSAO_VENCIDA], 401));

            return;
        }
        if ($sessao instanceof FlashBagAwareSessionInterface) {
            $sessao->getFlashBag()->add('error', $this->translator->trans('entrada.sessao.encerrada_em_todos'));
        }
        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_login')));
    }
}
