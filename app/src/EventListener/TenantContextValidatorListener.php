<?php
declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Repository\UserTenantRepository;
use App\Security\PedidoDeNavegacao;
use App\Service\Tenant\TenantContext;
use App\Tenant\Enum\SituacaoNaEquipe;
use App\Tenant\Service\AvisoDeContaSuspensa;
use App\Tenant\Service\BloqueioPorPedidoDeExclusao;
use App\Tenant\Service\RegraDosPrimarios;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;

// Prioridade 6: roda depois do firewall (8) e do gate de Termos de Uso (7),
// garantindo que o aceite da plataforma venha antes da seleção de escritório.
#[AsEventListener(event: KernelEvents::REQUEST, priority: 6)]
final class TenantContextValidatorListener
{
    private const ROTAS_IGNORADAS = [
        'app_login',
        // Entrar com o Google: a ida e a volta acontecem antes de haver escritório ou aceite.
        'app_login_google',
        'app_login_google_volta',
        // Continuar com Facebook (PR B): a ida e a volta também acontecem antes de haver escritório ou aceite.
        'app_login_facebook',
        'app_login_facebook_volta',
        'app_logout',
        'tenant_selecionar',
        // Criar escritório não exige tenant ativo — quem tem 0 escritórios precisa
        // alcançar esta tela para abrir o primeiro.
        'escritorio_criar',
        'admin_platform_dashboard',
        // Aceite dos Termos é da plataforma e independe de tenant — a tela precisa
        // ser alcançável antes da seleção de escritório (ver TermoAceiteListener).
        'termo_aceite',
        'termo_aceite_registrar',
        // Documento legal público: não depende de tenant nem de usuário. Precisa estar aqui
        // *além* de na lista do TermoAceiteListener — são dois portões diferentes, e este roda
        // logo depois daquele (prioridade 6 contra 7). Liberar só lá deixava o link quebrado
        // para quem está logado sem escritório selecionado, que é justamente quem está parado
        // em tenant_selecionar e em termo_aceite — as duas telas que oferecem o link.
        'legal_politica_privacidade',
        'legal_politica_privacidade_pdf',
        // Convite do Trabalho Externo: quem aceita pode não ter escritório nenhum (spec §8).
        'trabalho_externo_convite',
        'trabalho_externo_convite_criar_conta',
        'trabalho_externo_convite_aceitar',
        // Descadastro de e-mail: link assinado aberto por quem pode nem ter conta (ou estar logado
        // em outro escritório). Não depende de termo nem de escritório; a guarda é a assinatura.
        'email_descadastro',
        'comercial_confirmar_endereco',
        'comercial_confirmar_endereco_gravar',
        'comercial_rastreio_abertura',
        'comercial_rastreio_clique',
        // Link externo do Push: página pública de terceiro, não depende de termo nem de escritório.
        'push_compartilhado',
        'push_compartilhado_codigo',
        'push_compartilhado_renovacao',
        // Página pública de vagas: sem login por desenho; quem está logado lê igual a quem não está.
        'trabalho_externo_vagas_publicas',
        'trabalho_externo_vagas_link',
        'trabalho_externo_vagas_alertas',
        'vagas_publicas_cta',
        // Indicar o BlueJus (spec indicar-bluejus.md): o menu oferece a janela a todo logado, com ou sem
        // escritório (o Convidado do Trabalho Externo também). O dado é da pessoa, sem tenant; o controller só
        // usa o escritório da sessão com vínculo ativo, conferido por ele mesmo.
        'indicacao_janela',
        'indicacao_email',
        'indicacao_canal',
    ];

    /**
     * Rotas que passam SEM escritório na sessão, mas que, com escritório, enfrentam as barreiras de sempre (conta
     * suspensa, vínculo removido, em análise). A janela "Como devemos tratar você?" (spec genero-no-cadastro, D2)
     * aparece também na seleção de escritório e para o Convidado do Trabalho Externo; os POSTs dela só gravam o gênero
     * do próprio usuário (getUser()), dado da pessoa, sem tenant.
     */
    private const ROTAS_SEM_ESCRITORIO = ['app_profile_genero', 'app_profile_genero_fechar'];

    /**
     * Prefixo de rotas que funcionam SEM escritório: o Trabalho Externo atende o Convidado, que só
     * tem convite para uma demanda e nenhum vínculo com escritório. As rotas não confiam nesta
     * liberação: cada uma resolve o perfil (`FabricaDeContexto`) e, sem escritório, só o Convidado
     * passa, e só para as demandas do convite.
     */
    private const PREFIXO_SEM_ESCRITORIO = 'trabalho_externo_';

    /** Rotas do painel do Master: funcionam sem escritório na sessão. */
    private const PREFIXOS_DO_PAINEL_DO_MASTER = ['admin_', 'master_', 'inteligencia_admin_', 'auth_suporte_'];

    private const ACESSO_INDISPONIVEL = 'Seu acesso a este escritório não está mais disponível. Selecione outro escritório.';

    public function __construct(
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
        private readonly RouterInterface $router,
        private readonly BloqueioPorPedidoDeExclusao $bloqueioPorPedido,
        private readonly RegraDosPrimarios $regraDosPrimarios,
        private readonly UserTenantRepository $userTenantRepository,
    ) {}

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $route = (string) $event->getRequest()->attributes->get('_route', '');

        if ($this->deveIgnorar($route)) {
            return;
        }

        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return;
        }

        if (!$this->tenantContext->hasCurrentTenant()) {
            if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true) && !$this->masterPrecisaDaSelecao($event->getRequest(), $route, $user)) {
                return;
            }
            // Só no ramo SEM escritório: com escritório na sessão, o vínculo continua conferido
            // abaixo, para o Trabalho Externo também.
            if (str_starts_with($route, self::PREFIXO_SEM_ESCRITORIO) || in_array($route, self::ROTAS_SEM_ESCRITORIO, true)) {
                return;
            }

            $event->setResponse(new RedirectResponse($this->router->generate('tenant_selecionar')));

            return;
        }

        // Barreiras de acesso ao escritório da sessão, nesta ordem (spec perfis-1.2-contas C13). Vale a
        // mensagem da primeira que falhar. Derrubam a sessão a cada request: fecham num ponto só os 40+
        // consumidores de getCurrentTenant() que não rechecam isActive (RS06/RS07).
        $userTenant = $this->tenantContext->getCurrentUserTenant();
        $recusa = $this->barreiraDaConta($userTenant);
        // (b) pedido de exclusão pendente (PR 5): o primário fica no Meu Perfil; a equipe sai.
        if ($recusa === null && $this->primarioNaContaSuspensaAPedido($userTenant, $event)) {
            return;
        }
        $recusa ??= $this->barreiraDoPedidoDeExclusao($userTenant);
        $recusa ??= $this->barreiraDoVinculo($userTenant);
        $recusa ??= $this->barreiraDaSituacao($userTenant, $user);

        if ($recusa === null) {
            return;
        }

        $this->tenantContext->clearCurrentTenant();
        $event->getRequest()->getSession()->getFlashBag()->add('warning', $recusa);
        $event->setResponse(new RedirectResponse($this->router->generate('tenant_selecionar')));
    }

    /**
     * (a) A conta: escritório suspenso pelo Master (isActive=false, excluidoEm nulo, aviso da C9) ou
     * excluído, em quarentena (aviso da C9b). Sem bypass para o Master (spec perfis-1.2-contas C12).
     */
    private function barreiraDaConta(?UserTenant $userTenant): ?string
    {
        $conta = $userTenant?->getTenant() ?? $this->tenantContext->getCurrentTenant();
        if ($conta !== null && $conta->isActive() === true) {
            return null;
        }

        if ($conta === null) {
            return self::ACESSO_INDISPONIVEL; // já purgada: não há mais nome a dizer
        }

        return $conta->getExcluidoEm() === null
            ? AvisoDeContaSuspensa::para((string) $conta->getName())
            : AvisoDeContaSuspensa::paraExcluida((string) $conta->getName());
    }

    /**
     * (b) Pedido de exclusão pendente ("suspensa a pedido", PR 5): quem não é primário não entra
     * ({@see BloqueioPorPedidoDeExclusao}). O primário é tratado antes, em
     * {@see primarioNaContaSuspensaAPedido()}.
     */
    private function barreiraDoPedidoDeExclusao(?UserTenant $userTenant): ?string
    {
        return $userTenant !== null && $userTenant->isActive() && $this->bloqueioPorPedido->bloqueia($userTenant)
            ? BloqueioPorPedidoDeExclusao::MENSAGEM
            : null;
    }

    /**
     * O primário de uma conta "suspensa a pedido" continua no escritório, mas só no Meu Perfil (onde
     * desiste) e no sair: qualquer outra rota volta ao Meu Perfil. Devolve true quando a requisição já foi
     * resolvida aqui (liberada ou redirecionada). Só vale com o vínculo ATIVO: o primário em análise ou suspenso
     * pela equipe (vínculo inativo) segue para a (c) e a (d), como qualquer pessoa.
     */
    private function primarioNaContaSuspensaAPedido(?UserTenant $userTenant, RequestEvent $event): bool
    {
        if ($userTenant === null || !$userTenant->isActive() || !$this->regraDosPrimarios->ehPrimario($userTenant)) {
            return false;
        }
        $tenantId = $userTenant->getTenant()->getId();
        if ($tenantId === null || !$this->bloqueioPorPedido->suspensaAPedido($tenantId)) {
            return false;
        }
        if (str_starts_with($event->getRequest()->getPathInfo(), '/perfil')) {
            return true;
        }

        // Chamada de fundo (sino, contadores, polling sem o cabeçalho XHR): recusa curta, sem flash acumulando
        // para a próxima página. Só a navegação de verdade (aceita HTML) vai ao Meu Perfil.
        $requisicao = $event->getRequest();
        if ($requisicao->isXmlHttpRequest() || !str_contains((string) $requisicao->headers->get('Accept'), 'text/html')) {
            $event->setResponse(new JsonResponse(['erro' => BloqueioPorPedidoDeExclusao::MENSAGEM], Response::HTTP_FORBIDDEN));

            return true;
        }
        $sessao = $event->getRequest()->getSession();
        if ($sessao instanceof FlashBagAwareSessionInterface) {
            $sessao->getFlashBag()->add('warning', BloqueioPorPedidoDeExclusao::MENSAGEM);
        }
        $event->setResponse(new RedirectResponse($this->router->generate('app_profile')));

        return true;
    }

    /** (c) O vínculo: removido, ou inativo de legado (inativo sem ser por causa da situação na equipe). */
    private function barreiraDoVinculo(?UserTenant $userTenant): ?string
    {
        if ($userTenant === null) {
            return self::ACESSO_INDISPONIVEL;
        }

        return !$userTenant->isActive() && $userTenant->getSituacao() === SituacaoNaEquipe::Aprovado ? self::ACESSO_INDISPONIVEL : null;
    }

    /**
     * (d) Situação na equipe: em análise ou suspenso pelo primário (spec docs/specs/equipe-da-conta.md §3). O Master
     * não passa por esta barreira.
     */
    private function barreiraDaSituacao(?UserTenant $userTenant, User $user): ?string
    {
        if ($userTenant === null || $userTenant->isActive() || in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            return null;
        }

        return $userTenant->getSituacao()->mensagemDeAcesso((string) $userTenant->getTenant()->getName());
    }

    /**
     * O Master sem escritório na sessão usa o painel dele livremente. Fora do painel, se tem vínculo ativo, escolhe
     * o escritório antes: sem isso, quem entrava pelo "lembrar-me" ou pelo véu com vários escritórios caía no
     * Expediente sem escritório e levava 403 (v2.9.0). Sem vínculo nenhum (ou só com escritório suspenso a
     * pedido), continua passando. Só a navegação de
     * página é desviada: o sino, o avatar e os outros pedidos de dados das telas do painel seguem como antes (sem
     * escolher escritório por baixo e sem uma consulta a cada sondagem).
     */
    private function masterPrecisaDaSelecao(Request $request, string $route, User $user): bool
    {
        if (!self::eAberturaDePagina($request)) {
            return false;
        }

        foreach (self::PREFIXOS_DO_PAINEL_DO_MASTER as $prefixo) {
            if (str_starts_with($route, $prefixo)) {
                return false;
            }
        }

        // Escritório "suspenso a pedido" (PR 5) não conta: a seleção não o oferece, e o Master cairia de volta no painel.
        return $this->bloqueioPorPedido->permitidos($this->userTenantRepository->findActiveByUser($user)) !== [];
    }

    /**
     * Só a abertura de página pelo navegador: GET que pede HTML (`text/html` no Accept), sem XHR e sem cara de pedido
     * de dados. POST, PUT e DELETE, sondagem, XHR e pedido sem Accept (os testes do KernelBrowser) seguem como antes.
     */
    private static function eAberturaDePagina(Request $request): bool
    {
        return $request->isMethod('GET')
            && PedidoDeNavegacao::eNavegacao($request)
            && str_contains(strtolower((string) $request->headers->get('Accept', '')), 'text/html');
    }

    private function deveIgnorar(string $route): bool
    {
        if (in_array($route, self::ROTAS_IGNORADAS, true)) {
            return true;
        }

        return str_starts_with($route, '_');
    }
}
