<?php
declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Auth\User;
use App\Repository\UserTenantRepository;
use App\Security\PedidoDeNavegacao;
use App\Service\Tenant\TenantContext;
use App\Tenant\Service\BarreirasDoEscritorio;
use App\Tenant\Service\BloqueioPorPedidoDeExclusao;
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
        // Conferência ao vivo do cupom do Criar conta (spec login-cupom-ao-vivo): pública, vale a anônimo e a logado sem escritório.
        'auth_cadastro_cupom_conferir',
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
        // Link do signatário da Assinatura de documentos: página pública de terceiro, não depende de termo nem de
        // escritório (o escritório sai do documento achado pelo hash do token).
        'assinatura_eletronica_publica',
        'assinatura_eletronica_publica_codigo',
        'assinatura_eletronica_publica_assinar',
        'assinatura_eletronica_publica_recusa',
        'assinatura_eletronica_publica_arquivo',
        'assinatura_eletronica_publica_logo',
        'assinatura_eletronica_publica_rubrica',
        'assinatura_eletronica_publica_pdf',
        'assinatura_eletronica_publica_invalido',
        // Validação pública do código BJA (etapa 3): quem lê o QR do PDF pode estar logado sem escritório ou sem aceite.
        'assinatura_eletronica_publica_validar',
        'assinatura_eletronica_publica_validar_codigo',
        'assinatura_eletronica_publica_validar_consulta',
        // Cédula da assembleia de condomínio (Voto): página pública do morador, não depende de termo nem de escritório.
        'voto_assembleia_portal',
        'voto_assembleia_portal_localizar',
        'voto_assembleia_portal_solicitar',
        'voto_assembleia_portal_confirmar',
        'voto_assembleia_portal_reiniciar',
        'voto_assembleia_portal_sair_terminal',
        'voto_assembleia_cedula',
        'voto_assembleia_cedula_votar',
        'voto_assembleia_cedula_comprovante_email',
        'voto_assembleia_cedula_foto',
        'voto_assembleia_cedula_participacao',
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
        // Validação do comprovante do ponto (E15, P50): a página do QR Code e a consulta da janela, abertas a quem
        // está logado sem escritório na sessão como a quem não tem conta.
        'validacao_comprovante_ponto',
        'validacao_comprovante_ponto_codigo',
        // Servidor de autorização do conector de I.A. (spec mcp-remoto-oauth 8). No /oauth/authorize a pessoa ainda não
        // escolheu o escritório da conexão: a própria tela o escolhe, e as barreiras do escritório escolhido são as do
        // PortaoDoConector::conferir. Token e metadado são chamados de servidor para servidor; ficam aqui para que uma
        // sessão logada sem escritório também não seja redirecionada para tenant_selecionar.
        'conector_oauth_autorizar',
        'conector_oauth_token',
        'conector_oauth_metadado',
        // O /mcp do conector: sem sessão, o escritório vem do token e as barreiras são do PortaoDoConector a cada
        // requisição; sem esta linha, o app de I.A. receberia um 302 para tenant_selecionar. O metadado do recurso
        // (RFC 9728, nas duas URLs) é chamado de servidor para servidor, como o do servidor de autorização.
        'conector_mcp',
        'conector_metadado_do_recurso',
        'conector_metadado_do_recurso_raiz',
    ];

    /**
     * Rotas que passam SEM escritório na sessão, mas que, com escritório, enfrentam as barreiras de sempre (conta
     * suspensa, vínculo removido, em análise). A janela "Como devemos tratar você?" (spec genero-no-cadastro, D2)
     * aparece também na seleção de escritório e para o Convidado do Trabalho Externo; os POSTs dela só gravam o gênero
     * do próprio usuário (getUser()), dado da pessoa, sem tenant.
     */
    private const ROTAS_SEM_ESCRITORIO = ['app_profile_genero', 'app_profile_genero_fechar'];

    /**
     * Default de rota que a libera SEM escritório (auditoria 1.09): o Trabalho Externo atende o Convidado, que só tem
     * convite para uma demanda e nenhum vínculo com escritório. Vai no `#[Route]` da classe de cada controller do TE;
     * o nome da rota não libera nada. Sem escritório o filtro de tenant fica desligado, então a guarda é da ação: cada
     * uma resolve o perfil pela `FabricaDeContexto`, que só devolve contexto ao Master ou ao Convidado, e só para as
     * demandas do convite. `TrabalhoExternoSemEscritorioArquiteturaTest` exige as duas coisas.
     */
    public const ATRIBUTO_SEM_ESCRITORIO = '_sem_escritorio';

    /** Rotas do painel do Master: funcionam sem escritório na sessão. */
    private const PREFIXOS_DO_PAINEL_DO_MASTER = ['admin_', 'master_', 'inteligencia_admin_', 'auth_suporte_'];

    public function __construct(
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
        private readonly RouterInterface $router,
        private readonly BloqueioPorPedidoDeExclusao $bloqueioPorPedido,
        private readonly BarreirasDoEscritorio $barreiras,
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
            if ($event->getRequest()->attributes->get(self::ATRIBUTO_SEM_ESCRITORIO) === true || in_array($route, self::ROTAS_SEM_ESCRITORIO, true)) {
                return;
            }

            $event->setResponse(new RedirectResponse($this->router->generate('tenant_selecionar')));

            return;
        }

        $this->aplicarBarreiras($event, $user);
    }

    private function aplicarBarreiras(RequestEvent $event, User $user): void
    {
        // Barreiras de acesso ao escritório da sessão, nesta ordem (spec perfis-1.2-contas C13). Vale a
        // mensagem da primeira que falhar. Derrubam a sessão a cada request: fecham num ponto só os 40+
        // consumidores de getCurrentTenant() que não rechecam isActive (RS06/RS07). A regra mora no
        // BarreirasDoEscritorio, que o conector de I.A. também usa (spec mcp-remoto-oauth 4.1).
        $userTenant = $this->tenantContext->getCurrentUserTenant();
        $resultado = $this->barreiras->conferir(
            $userTenant,
            $userTenant?->getTenant() ?? $this->tenantContext->getCurrentTenant(),
            $user,
            passeDoMaster: true,
        );
        // (b) pedido de exclusão pendente (PR 5): o primário fica no Meu Perfil; a equipe sai.
        if ($resultado->ePrimarioNaContaSuspensaAPedido()) {
            $this->prenderAoMeuPerfil($event);

            return;
        }
        $recusa = $resultado->recusa();

        if ($recusa === null) {
            return;
        }

        $this->tenantContext->clearCurrentTenant();
        $event->getRequest()->getSession()->getFlashBag()->add('warning', $recusa);
        $event->setResponse(new RedirectResponse($this->router->generate('tenant_selecionar')));
    }

    /**
     * O primário de uma conta "suspensa a pedido" ({@see BarreirasDoEscritorio}) continua no escritório, mas só no Meu
     * Perfil (onde desiste) e no sair: qualquer outra rota volta ao Meu Perfil.
     */
    private function prenderAoMeuPerfil(RequestEvent $event): void
    {
        if (str_starts_with($event->getRequest()->getPathInfo(), '/perfil')) {
            return;
        }

        // Chamada de fundo (sino, contadores, polling sem o cabeçalho XHR): recusa curta, sem flash acumulando
        // para a próxima página. Só a navegação de verdade (aceita HTML) vai ao Meu Perfil.
        $requisicao = $event->getRequest();
        if ($requisicao->isXmlHttpRequest() || !str_contains((string) $requisicao->headers->get('Accept'), 'text/html')) {
            $event->setResponse(new JsonResponse(['erro' => BloqueioPorPedidoDeExclusao::MENSAGEM], Response::HTTP_FORBIDDEN));

            return;
        }
        $sessao = $event->getRequest()->getSession();
        if ($sessao instanceof FlashBagAwareSessionInterface) {
            $sessao->getFlashBag()->add('warning', BloqueioPorPedidoDeExclusao::MENSAGEM);
        }
        $event->setResponse(new RedirectResponse($this->router->generate('app_profile')));
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
