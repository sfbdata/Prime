<?php

declare(strict_types=1);

namespace App\Tarefa\Controller;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Tarefa\UseCase\ListarMetasDaEquipeUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lista "Metas da equipe": destino dos números de metas da tabela Desempenho do Dashboard.
 *
 * A guarda é a MESMA do DashboardController (módulo `bi`): quem vê o número tem de poder abrir
 * a lista que ele conta — e só quem vê o número.
 */
final class TarefaEquipeController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly ListarMetasDaEquipeUseCase $useCase,
    ) {
    }

    /**
     * `priority` é obrigatório: o TarefaController (src/Controller/, carregado antes) tem
     * `/tarefas/{id}` sem restrição, que capturaria "equipe" como id e responderia 404.
     */
    #[Route('/tarefas/equipe', name: 'tarefa_equipe', methods: ['GET'], priority: 10)]
    public function equipe(Request $request): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->assertAccess($usuario);

        $filtros = [
            'status'      => (string) $request->query->get('status', ''),
            'responsavel' => (string) $request->query->get('responsavel', ''),
            'cargo'       => (string) $request->query->get('cargo', ''),
            'data_de'     => (string) $request->query->get('data_de', ''),
            'data_ate'    => (string) $request->query->get('data_ate', ''),
        ];

        return $this->render('tarefa/equipe.html.twig', [
            'lista' => $this->useCase->executar($tenant, new \DateTimeImmutable(), $filtros, (int) $request->query->get('page', '1')),
        ]);
    }

    private function assertAccess(User $user): Tenant
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null || !$this->permissionChecker->canAccessModule($user, $tenant, 'bi')) {
            throw $this->createAccessDeniedException('Sem acesso ao módulo Dashboard.');
        }

        return $tenant;
    }
}
