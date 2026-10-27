<?php

declare(strict_types=1);

namespace App\Security;

use App\Shared\Contract\ConfiguracaoDaPlataforma;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\RememberMeToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Expira a sessão por inatividade (spec `login-sessao-expiracao.md`). A duração vem da configuração `sessao` do
 * Master (minutos), lida a cada requisição: a mudança vale na seguinte.
 *
 * Roda depois do firewall (prioridade 8; aqui 7). Só conta usuário autenticado POR SESSÃO: quem entrou pelo
 * "lembrar-me" (RememberMeToken) não expira aqui, e requisição sem sessão anterior nem toca a sessão. A inatividade é
 * `agora - MetadataBag::getLastUsed()`. O `getLastUsed()` guarda o instante do pedido ANTERIOR (o MetadataBag carrega o
 * carimbo gravado e só então atualiza o `u` da sessão), então a leitura feita agora, com o carimbo desta requisição já
 * regravado, ainda mede a pausa real entre dois pedidos.
 *
 * Passou do prazo: invalida a sessão, limpa o token e responde. Navegação vai a /login com o aviso (flash); pedido de
 * dados (XHR, `fetch`) recebe 401 em JSON, como o `PedidoDeNavegacao` já faz para sessão vencida.
 *
 * Nunca lança por causa da configuração: valor inválido ou leitor que falha valem o padrão do ambiente
 * (`app.sessao_padrao_minutos`).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
final class ExpiracaoDaSessaoListener
{
    public const MINIMO_MINUTOS = 5;
    public const MAXIMO_MINUTOS = 43200;

    /** Prefixos que a expiração nunca atinge: a tela de entrada, o sair e os arquivos estáticos/ferramentas. */
    private const IGNORADOS = ['/login', '/logout', '/assets', '/build', '/bundles', '/_wdt', '/_profiler'];

    public function __construct(
        private readonly ConfiguracaoDaPlataforma $configuracao,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
        #[Autowire('%app.sessao_padrao_minutos%')]
        private readonly int $sessaoPadraoMinutos,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $this->ignorada($request) || !$request->hasPreviousSession()) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        if ($token === null || $token instanceof RememberMeToken || !is_object($token->getUser())) {
            return;
        }

        $sessao = $request->getSession();
        $inativoSegundos = $this->clock->now()->getTimestamp() - $sessao->getMetadataBag()->getLastUsed();
        if ($inativoSegundos <= $this->minutosDeSessao() * 60) {
            return;
        }

        $this->tokenStorage->setToken(null);
        $sessao->invalidate();

        if (!PedidoDeNavegacao::eNavegacao($request)) {
            $event->setResponse(new JsonResponse(['erro' => PedidoDeNavegacao::MENSAGEM_SESSAO_VENCIDA], 401));

            return;
        }

        // Depois do invalidate() a sessão é nova: o flash vai nela e aparece no /login.
        if ($sessao instanceof FlashBagAwareSessionInterface) {
            $sessao->getFlashBag()->add('error', $this->translator->trans('entrada.sessao.expirada'));
        }
        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('app_login')));
    }

    private function ignorada(Request $request): bool
    {
        $caminho = $request->getPathInfo();
        foreach (self::IGNORADOS as $prefixo) {
            if ($caminho === $prefixo || str_starts_with($caminho, $prefixo . '/')) {
                return true;
            }
        }

        return false;
    }

    /** Minutos da configuração; fora de 5..43200, não numérico ou leitor falhando: o padrão do ambiente. */
    private function minutosDeSessao(): int
    {
        try {
            $bruto = $this->configuracao->valor('sessao');
        } catch (\Throwable) {
            return $this->padrao();
        }

        if (!preg_match('/^\d{1,9}$/', trim($bruto))) {
            return $this->padrao();
        }

        $minutos = (int) $bruto;

        return $minutos >= self::MINIMO_MINUTOS && $minutos <= self::MAXIMO_MINUTOS ? $minutos : $this->padrao();
    }

    private function padrao(): int
    {
        $p = $this->sessaoPadraoMinutos;

        return $p >= 1 && $p <= self::MAXIMO_MINUTOS ? $p : 24;
    }
}
