<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Auth\User;
use App\Termo\Repository\AceiteTermoRepository;
use App\Termo\TermoVigente;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;

/**
 * Gate de aceite dos Termos de Uso.
 *
 * Ordem em kernel.request: firewall do Symfony (prioridade 8) → este gate (7) →
 * TenantContextValidatorListener (6). Precisa rodar DEPOIS do firewall (senão getUser()
 * é null numa request real baseada em sessão) e ANTES do gate de tenant — o aceite da
 * plataforma vem antes da seleção de escritório. Sem bypass de super admin: vale para todos.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
final class TermoAceiteListener
{
    /** Marcador de sessão que evita consultar o banco a cada request. */
    public const SESSION_KEY = 'termo_aceito_versao';

    private const ROTAS_IGNORADAS = [
        'app_login',
        'app_logout',
        'tenant_selecionar',
        'termo_aceite',
        'termo_aceite_registrar',
        'auth_aceite_convite',
        'auth_aceite_convite_plataforma',
        'auth_aceite_convite_criar_conta',
        'auth_aceite_convite_aceitar',
        'auth_aceite_convite_recusar',
        // Quem está sendo parado para aceitar os Termos precisa poder ler a Política de
        // Privacidade antes de decidir. São rotas públicas e somente de leitura: liberá-las
        // aqui não afrouxa o portão do aceite, só deixa de transformar o link em beco sem saída.
        'legal_politica_privacidade',
        'legal_politica_privacidade_pdf',
    ];

    public function __construct(
        private readonly Security $security,
        private readonly AceiteTermoRepository $aceiteTermoRepository,
        private readonly TermoVigente $termoVigente,
        private readonly RouterInterface $router,
    ) {}

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route', '');

        if ($this->deveIgnorar($route)) {
            return;
        }

        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return;
        }

        $versao = $this->termoVigente->getVersao();

        if ($request->getSession()->get(self::SESSION_KEY) === $versao) {
            return;
        }

        if ($this->aceiteTermoRepository->existeAceiteVigente($user, $versao)) {
            $request->getSession()->set(self::SESSION_KEY, $versao);

            return;
        }

        $event->setResponse($this->respostaBloqueio($request));
    }

    private function deveIgnorar(string $route): bool
    {
        if (in_array($route, self::ROTAS_IGNORADAS, true)) {
            return true;
        }

        return str_starts_with($route, '_');
    }

    /**
     * Redireciona para a tela de aceite — exceto em requisições que esperam JSON (AJAX),
     * onde um 302 quebraria a chamada; nesse caso responde 403.
     */
    private function respostaBloqueio(Request $request): Response
    {
        if ($request->isXmlHttpRequest() || $request->getPreferredFormat() === 'json') {
            return new JsonResponse(
                ['erro' => 'É necessário aceitar os Termos de Uso para continuar.'],
                Response::HTTP_FORBIDDEN,
            );
        }

        return new RedirectResponse($this->router->generate('termo_aceite'));
    }
}
