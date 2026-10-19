<?php

declare(strict_types=1);

namespace App\Master\Controller;

use App\Entity\Auth\User;
use App\Master\DTO\PromoverMasterInput;
use App\Master\DTO\RemoverMasterInput;
use App\Master\Exception\OperacaoDeMasterRecusada;
use App\Master\UseCase\PromoverMasterUseCase;
use App\Master\UseCase\RemoverMasterUseCase;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Bloco "Masters do sistema" da aba Permissões de /master (desenho 05 - Perfis 1.2, spec
 * docs/specs/perfis-1.2-masters.md §4.2): promover um secundário do escritório a Master secundário
 * e remover um Master secundário.
 *
 * Duas camadas: ROLE_SUPER_ADMIN na classe (quem não é Master recebe 403) e, nos UseCases, a regra
 * de que só o Master PRIMÁRIO promove e remove (o secundário recebe a recusa no flash).
 * Volta sempre para /master#aba=perm, que o painel.js abre direto na aba Permissões.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class MastersDoSistemaController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/master/masters/promover', name: 'master_promover', methods: ['POST'])]
    public function promover(Request $request, PromoverMasterUseCase $promover): RedirectResponse
    {
        $this->exigirCsrf('master_promover', $request);

        try {
            $promovido = $promover->executar(new PromoverMasterInput(
                $this->ator(),
                $this->tenantContext->getCurrentTenant(),
                $request->request->getInt('usuario'),
            ));
            $this->addFlash('success', sprintf('"%s" agora é Master secundário.', $promovido->nome));
        } catch (OperacaoDeMasterRecusada $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->voltarParaPermissoes();
    }

    #[Route('/master/masters/{userId}/remover', name: 'master_remover', requirements: ['userId' => '\d+'], methods: ['POST'])]
    public function remover(int $userId, Request $request, RemoverMasterUseCase $remover): RedirectResponse
    {
        $this->exigirCsrf('master_remover_' . $userId, $request);

        try {
            $removido = $remover->executar(new RemoverMasterInput($this->ator(), $userId));
            $this->addFlash('success', sprintf('"%s" deixou de ser Master.', $removido->nome));
        } catch (OperacaoDeMasterRecusada $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->voltarParaPermissoes();
    }

    private function exigirCsrf(string $id, Request $request): void
    {
        if (!$this->isCsrfTokenValid($id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
    }

    private function ator(): User
    {
        $ator = $this->getUser();
        if (!$ator instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $ator;
    }

    private function voltarParaPermissoes(): RedirectResponse
    {
        return $this->redirect($this->generateUrl('master_painel') . '#aba=perm');
    }
}
