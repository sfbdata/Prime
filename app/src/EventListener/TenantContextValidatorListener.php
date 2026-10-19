<?php
declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Service\Tenant\TenantContext;
use App\Tenant\Service\AvisoDeContaSuspensa;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
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
        // Link externo do Push: página pública de terceiro, não depende de termo nem de escritório.
        'push_compartilhado',
        'push_compartilhado_codigo',
        'push_compartilhado_renovacao',
        // Página pública de vagas: sem login por desenho; quem está logado lê igual a quem não está.
        'trabalho_externo_vagas_publicas',
        'trabalho_externo_vagas_link',
        'trabalho_externo_vagas_alertas',
    ];

    /**
     * Prefixo de rotas que funcionam SEM escritório: o Trabalho Externo atende o Convidado, que só
     * tem convite para uma demanda e nenhum vínculo com escritório. As rotas não confiam nesta
     * liberação: cada uma resolve o perfil (`FabricaDeContexto`) e, sem escritório, só o Convidado
     * passa, e só para as demandas do convite.
     */
    private const PREFIXO_SEM_ESCRITORIO = 'trabalho_externo_';

    private const ACESSO_INDISPONIVEL = 'Seu acesso a este escritório não está mais disponível. Selecione outro escritório.';

    public function __construct(
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
        private readonly RouterInterface $router,
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
            if (in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
                return;
            }
            // Só no ramo SEM escritório: com escritório na sessão, o vínculo continua conferido
            // abaixo, para o Trabalho Externo também.
            if (str_starts_with($route, self::PREFIXO_SEM_ESCRITORIO)) {
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
        // (b) pedido de exclusão pendente: entra aqui (frente 7d, PR 5).
        $recusa ??= $this->barreiraDoVinculo($userTenant);
        // (d) situação do usuário no escritório: entra aqui (frente 7d, PR 4).

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

    /** (c) O vínculo: removido ou desativado no escritório. */
    private function barreiraDoVinculo(?UserTenant $userTenant): ?string
    {
        return $userTenant !== null && $userTenant->isActive() ? null : self::ACESSO_INDISPONIVEL;
    }

    private function deveIgnorar(string $route): bool
    {
        if (in_array($route, self::ROTAS_IGNORADAS, true)) {
            return true;
        }

        return str_starts_with($route, '_');
    }
}
