<?php

declare(strict_types=1);

namespace App\Conector\Controller;

use App\Conector\UseCase\AlterarConectorDoEscritorioUseCase;
use App\Entity\Auth\User;
use App\Inteligencia\Controller\ConfiguracaoController;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * O administrador do escritório liga (com o aceite) ou desliga o conector de I.A. no cartão da tela "BlueJus IA"
 * (`docs/specs/mcp-remoto-oauth.md`, 14.a, nível 2; a guarda é a mesma da tela: `admin.inteligencia.manage`). O
 * escritório é sempre o da sessão ({@see TenantContext}); o formulário não manda escritório. CSRF `_token`, flash e
 * volta para a tela.
 *
 * Campos do POST: `acao` (`ligar` ou `desligar`; outro valor não muda nada), `aceite` (a caixa "Li e aceito", só no
 * ligar) e `_token`.
 */
final class ConectorDoEscritorioController extends AbstractController
{
    public const TOKEN = 'conector_escritorio';

    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly AlterarConectorDoEscritorioUseCase $alterar,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    #[Route('/admin/inteligencia/conector', name: 'conector_admin_alterar', methods: ['POST'])]
    public function alterar(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        if ($tenant === null || !$this->permissionChecker->canAdminister($user, $tenant, ConfiguracaoController::PERMISSAO)) {
            throw $this->createAccessDeniedException('Sem permissão para configurar o conector de I.A.');
        }

        if (!$this->isCsrfTokenValid(self::TOKEN, (string) $request->request->get('_token', ''))) {
            $this->addFlash('error', $this->tradutor->trans('conector.admin.recusa.token_invalido'));

            return $this->redirectToRoute('inteligencia_admin_config');
        }

        $acao = $request->request->get('acao');
        try {
            if ($acao === 'desligar') {
                $this->alterar->desligar($tenant);
                $this->addFlash('success', $this->tradutor->trans('conector.admin.desligou'));
            }
            if ($acao === 'ligar') {
                $this->alterar->ligar($request->request->get('aceite') === '1', $user, $tenant);
                $this->addFlash('success', $this->tradutor->trans('conector.admin.ligou'));
            }
        } catch (\DomainException $e) {
            $this->addFlash('error', $this->tradutor->trans($e->getMessage()));
        }

        return $this->redirectToRoute('inteligencia_admin_config');
    }
}
