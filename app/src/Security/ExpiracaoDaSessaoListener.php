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
use Symfony\Component\HttpFoundation\Session\SessionInterface;
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
 * `agora - CARIMBO`, um carimbo próprio na sessão que só o pedido autenticado de dentro renova (e o login, pelo
 * `MarcaDaSessaoNoLoginListener`). Nunca o `MetadataBag`: o Symfony o regrava em toda abertura da sessão, e abrir o
 * /login ou outra rota pública renovaria a sessão vencida (auditoria 3.20). Sessão de antes do carimbo usa o
 * `getLastUsed()` só como reserva, e a rota ignorada o copia para o carimbo sem renovar.
 *
 * Passou do prazo: invalida a sessão, limpa o token e responde. Navegação vai a /login com o aviso (flash); pedido de
 * dados (XHR, `fetch`) recebe 401 em JSON, como o `PedidoDeNavegacao` já faz para sessão vencida.
 *
 * "Manter conectado" pelo formulário deixa um token comum na sessão: o que o distingue é a marca MANTER_CONECTADO,
 * gravada pelo servidor no login quando o badge do lembrar-me ficou ligado. Nunca o cookie REMEMBERME, que o cliente
 * cria com qualquer valor (auditoria 3.19). Rotas PUBLIC_ACCESS do access_control (derivadas do
 * `security.access_map`) também ficam de fora.
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

    /** Carimbo próprio do último pedido autenticado de dentro (segundos Unix). Rota ignorada nunca o renova. */
    public const CARIMBO = '_bj_ultimo_uso';

    /** Marca do servidor, gravada no login: a pessoa marcou "manter conectado" (o badge do lembrar-me ficou ligado). */
    public const MANTER_CONECTADO = '_bj_manter_conectado';

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
        if (!$event->isMainRequest() || !$request->hasPreviousSession()) {
            return;
        }
        if ($this->ignorada($request)) {
            $this->guardarCarimboSemRenovar($request);

            return;
        }

        $token = $this->tokenStorage->getToken();
        if ($token === null || $token instanceof RememberMeToken || !is_object($token->getUser())) {
            return;
        }

        $sessao = $request->getSession();
        if ($sessao->get(self::MANTER_CONECTADO) === true) {
            return;
        }

        $agora = $this->clock->now()->getTimestamp();
        if ($agora - $this->ultimoUso($sessao) <= $this->prazoEfetivoMinutos() * 60) {
            $sessao->set(self::CARIMBO, $agora);

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

    /** O carimbo próprio; sessão aberta antes dele existir: o `getLastUsed()` (o instante do pedido anterior). */
    private function ultimoUso(SessionInterface $sessao): int
    {
        $carimbo = $sessao->get(self::CARIMBO);

        return is_int($carimbo) ? $carimbo : $sessao->getMetadataBag()->getLastUsed();
    }

    /**
     * Rota ignorada nunca renova. Sessão ainda sem o carimbo próprio recebe o `getLastUsed()` de agora (o instante do
     * pedido ANTERIOR), antes que esta abertura o regrave: assim o /login não renova nem a sessão de antes do deploy.
     */
    private function guardarCarimboSemRenovar(Request $request): void
    {
        $sessao = $request->getSession();
        if (!$sessao->has(self::CARIMBO)) {
            $sessao->set(self::CARIMBO, $sessao->getMetadataBag()->getLastUsed());
        }
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
