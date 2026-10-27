<?php

declare(strict_types=1);

namespace App\Security;

use App\Auth\Service\ContaAConfirmar;
use App\Entity\Auth\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Sessão já aberta de conta com o e-mail "a confirmar" (spec `cadastro-exige-confirmacao.md`, comportamento 3):
 * termina no próximo pedido. A navegação volta ao login, que mostra "Confirme o seu e-mail para entrar..." e o botão
 * de reenviar (a chave da sessão é gravada depois do logout, na sessão nova); o pedido de dados recebe o 401 em JSON
 * de sempre (`PedidoDeNavegacao`).
 *
 * Prioridade 7: depois do firewall (8), que resolve o token; antes do `TenantContextValidatorListener` (6), para a
 * conta não ser mandada à seleção de escritório antes de sair. Divide a prioridade com o `TermoAceiteListener`; a
 * conta do cadastro já aceitou os Termos, e se o gate dos Termos responder antes, o pedido seguinte cai aqui.
 *
 * O login e o lembrar-me já recusam a conta no `UserChecker::checkPostAuth`; este listener fecha as sessões abertas
 * antes da regra e as autenticações que não passam pelo checker (o `loginUser` dos testes, por exemplo).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
final class SessaoDeContaAConfirmarListener
{
    private const FIREWALL = 'main';

    /** O próprio fluxo de quem ainda vai confirmar: entrada, saída, link do e-mail, reenvio, senha e Política. */
    private const ROTAS_LIBERADAS = [
        'app_login',
        'app_logout',
        'auth_cadastro_confirmar_email',
        'auth_cadastro_criar_senha',
        'auth_confirmacao_reenviar_publico',
        'auth_senha_esqueci',
        'auth_senha_codigo_enviar',
        'auth_senha_codigo_confirmar',
        'auth_senha_redefinir',
        'legal_politica_privacidade',
        'legal_politica_privacidade_pdf',
    ];

    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ContaAConfirmar $contaAConfirmar,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$this->seAplica($request)) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User || $user->isEmailConfirmado()) {
            return;
        }

        // O logout invalida a sessão; a chave do reenvio vai para a sessão nova, depois dele.
        $this->security->logout(false);
        if ($request->hasSession()) {
            $this->contaAConfirmar->guardar($request->getSession(), $user);
        }

        $event->setResponse(PedidoDeNavegacao::eNavegacao($request) || PedidoDeNavegacao::eQuadroInterno($request)
            ? new RedirectResponse($this->urlGenerator->generate('app_login'))
            : PedidoDeNavegacao::respostaDeSessaoVencida());
    }

    private function seAplica(Request $request): bool
    {
        $rota = (string) $request->attributes->get('_route', '');

        return !in_array($rota, self::ROTAS_LIBERADAS, true)
            && $this->security->getFirewallConfig($request)?->getName() === self::FIREWALL;
    }
}
