<?php
declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Tenant\Enum\SituacaoNaEquipe;
use App\Service\Tenant\TenantContext;
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
    ];

    /**
     * Prefixo de rotas que funcionam SEM escritório: o Trabalho Externo atende o Convidado, que só
     * tem convite para uma demanda e nenhum vínculo com escritório. As rotas não confiam nesta
     * liberação: cada uma resolve o perfil (`FabricaDeContexto`) e, sem escritório, só o Convidado
     * passa, e só para as demandas do convite.
     */
    private const PREFIXO_SEM_ESCRITORIO = 'trabalho_externo_';

    private const SEM_ACESSO = 'Seu acesso a este escritório não está mais disponível. Selecione outro escritório.';

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

        $userTenant = $this->tenantContext->getCurrentUserTenant();

        // Barreiras do acesso ao escritório da sessão, nesta ordem (contrato com a frente das contas, spec
        // docs/specs/equipe-da-conta.md §3 e perfis-1.2-contas.md C3/C13); vale a mensagem da primeira que falhar.
        // Fecha num ponto só os 40+ consumidores de getCurrentTenant() que não rechecam o vínculo (RS06/RS07).
        // (b) pedido de exclusão pendente entra entre (a) e (c), com o PR 5 do redesenho do Meu Perfil.
        $mensagem = $this->barreiraDoEscritorio($userTenant)
            ?? $this->barreiraDoVinculo($userTenant)
            ?? $this->barreiraDaSituacao($userTenant, $user);
        if ($mensagem === null) {
            return;
        }

        $this->tenantContext->clearCurrentTenant();
        $event->getRequest()->getSession()->getFlashBag()->add('warning', $mensagem);
        $event->setResponse(new RedirectResponse($this->router->generate('tenant_selecionar')));
    }

    /** (a) Escritório suspenso ou excluído (soft delete) por outro admin ou pelo Master. */
    private function barreiraDoEscritorio(?UserTenant $userTenant): ?string
    {
        return $userTenant !== null && $userTenant->getTenant()->isActive() !== true ? self::SEM_ACESSO : null;
    }

    /** (c) Vínculo removido, ou inativo de legado (inativo sem ser por causa da situação na equipe). */
    private function barreiraDoVinculo(?UserTenant $userTenant): ?string
    {
        if ($userTenant === null) {
            return self::SEM_ACESSO;
        }

        return !$userTenant->isActive() && $userTenant->getSituacao() === SituacaoNaEquipe::Aprovado ? self::SEM_ACESSO : null;
    }

    /** (d) Situação na equipe: em análise ou suspenso pelo primário. O Master não passa por esta barreira. */
    private function barreiraDaSituacao(?UserTenant $userTenant, User $user): ?string
    {
        if ($userTenant === null || $userTenant->isActive() || in_array('ROLE_SUPER_ADMIN', $user->getRoles(), true)) {
            return null;
        }

        return $userTenant->getSituacao()->mensagemDeAcesso((string) $userTenant->getTenant()->getName());
    }

    private function deveIgnorar(string $route): bool
    {
        if (in_array($route, self::ROTAS_IGNORADAS, true)) {
            return true;
        }

        return str_starts_with($route, '_');
    }
}
