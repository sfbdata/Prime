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
use Symfony\Component\Security\Http\AccessMapInterface;
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
 * "Manter conectado" pelo formulário deixa um token comum na sessão: se a requisição traz o cookie REMEMBERME, não
 * derruba. Rotas PUBLIC_ACCESS do access_control (derivadas do `security.access_map`) também ficam de fora.
 *
 * Teto operacional: o prazo efetivo é min(`sessao`, `app.sessao_gc_segundos` em minutos). O `gc_maxlifetime` do PHP é o
 * mesmo parâmetro, e o arquivo da sessão some depois dele; acima de 1 dia (padrão) é preciso subir a env
 * `SESSAO_GC_SEGUNDOS`. Custo: sessões anônimas de /login ficam esse tempo em var/sessions.
 *
 * Nunca lança por causa da configuração: valor inválido ou leitor que falha valem o padrão do ambiente
 * (`app.sessao_padrao_minutos`).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
final class ExpiracaoDaSessaoListener
{
    public const MINIMO_MINUTOS = 5;
    public const MAXIMO_MINUTOS = 43200;

    /** Cookie do "lembrar-me" do firewall main (`debug:config security firewalls.main.remember_me`: name REMEMBERME). */
    private const COOKIE_LEMBRAR_ME = 'REMEMBERME';

    /** Prefixos que a expiração nunca atinge além das rotas PUBLIC_ACCESS: o sair e os arquivos estáticos/ferramentas. */
    private const IGNORADOS = ['/login', '/logout', '/assets', '/build', '/bundles', '/_wdt', '/_profiler'];

    public function __construct(
        private readonly ConfiguracaoDaPlataforma $configuracao,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
        #[Autowire('%app.sessao_padrao_minutos%')]
        private readonly int $sessaoPadraoMinutos,
        #[Autowire('%app.sessao_gc_segundos%')]
        private readonly int $gcSegundos,
        #[Autowire(service: 'security.access_map')]
        private readonly AccessMapInterface $accessMap,
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

        // "Manter conectado" pelo formulário deixa um token comum na sessão: o cookie do lembrar-me é o que o distingue.
        if ($request->cookies->has(self::COOKIE_LEMBRAR_ME)) {
            return;
        }

        $sessao = $request->getSession();
        $inativoSegundos = $this->clock->now()->getTimestamp() - $sessao->getMetadataBag()->getLastUsed();
        if ($inativoSegundos <= $this->prazoEfetivoMinutos() * 60) {
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
        // Rotas PUBLIC_ACCESS do access_control (convite, senha, cadastro, páginas públicas, rastreio de e-mail...):
        // quem as abre não está "dentro" do sistema, e derrubar a sessão ali só atrapalha. Lista derivada, não copiada.
        if (in_array('PUBLIC_ACCESS', $this->accessMap->getPatterns($request)[0] ?? [], true)) {
            return true;
        }

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

    /** Prazo efetivo: o menor entre a configuração e o `gc_maxlifetime` (o arquivo da sessão some depois dele). */
    private function prazoEfetivoMinutos(): int
    {
        return min($this->minutosDeSessao(), max(1, intdiv($this->gcSegundos, 60)));
    }

    private function padrao(): int
    {
        $p = $this->sessaoPadraoMinutos;

        return $p >= 1 && $p <= self::MAXIMO_MINUTOS ? $p : 24;
    }
}
