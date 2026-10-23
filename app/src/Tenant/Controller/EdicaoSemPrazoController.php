<?php

declare(strict_types=1);

namespace App\Tenant\Controller;

use App\Entity\Auth\User;
use App\Repository\TenantRoleRepository;
use App\Service\Tenant\TenantContext;
use App\Tenant\Exception\OperacaoDePrimarioRecusada;
use App\Tenant\UseCase\AlterarEdicaoSemPrazoUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A opção "Usuário primário pode editar e excluir qualquer registro sem limite de tempo", na página do perfil "Usuário
 * Primário" em Perfis (spec `edicao-sem-prazo-master-primario.md` §3). Escritório da sessão (o da rota tem de ser o
 * mesmo), CSRF e a guarda `ehPrimario` no UseCase; quem não passa recebe 403.
 */
final class EdicaoSemPrazoController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly TranslatorInterface $tradutor,
    ) {
    }

    #[Route('/tenant/{tenantId}/roles/primarios/edicao-sem-prazo', name: 'app_tenant_role_edicao_sem_prazo', requirements: ['tenantId' => '\d+'], methods: ['POST'])]
    public function alterar(int $tenantId, Request $request, AlterarEdicaoSemPrazoUseCase $useCase, TenantRoleRepository $perfis): RedirectResponse
    {
        $usuario = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$usuario instanceof User || $tenant === null || $tenant->getId() !== $tenantId) {
            throw $this->createAccessDeniedException('Você não tem acesso aos perfis deste escritório.');
        }
        if (!$this->isCsrfTokenValid('edicao_sem_prazo_' . $tenantId, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Token CSRF inválido.');
        }
        try {
            $useCase->executar($usuario, $tenant, $request->request->getBoolean('ligado'));
        } catch (OperacaoDePrimarioRecusada $e) {
            throw $this->createAccessDeniedException($e->getMessage(), $e);
        }
        $this->addFlash('success', $this->tradutor->trans('perfis.editar.sem_prazo.salvo'));
        $perfil = $perfis->findPerfilDoPrimario($tenant);

        return $perfil === null
            ? $this->redirectToRoute('app_tenant_role_index', ['tenantId' => $tenantId])
            : $this->redirectToRoute('app_tenant_role_edit', ['tenantId' => $tenantId, 'id' => $perfil->getId()]);
    }
}
