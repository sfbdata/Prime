<?php

declare(strict_types=1);

namespace App\Dashboard\Controller;

use App\Dashboard\UseCase\BuscarPastasParaTarefaDoAlertaUseCase;
use App\Entity\Auth\User;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use App\Tarefa\Service\AcessoAoModuloTarefas;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Busca de pastas do seletor do botão "Criar tarefa" de um alerta da Inteligência:
 * GET /dashboard/inteligencia/pastas-para-tarefa?q=
 *
 * Só quem acessa o Dashboard (`bi`) E o módulo de tarefas: sem um dos dois, 403. A criação em si
 * continua na rota da pasta (`tarefa_criar_para_pasta`), com as guardas dela.
 */
final class PastasParaTarefaDoAlertaController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly AcessoAoModuloTarefas $moduloTarefas,
        private readonly BuscarPastasParaTarefaDoAlertaUseCase $buscarPastas,
    ) {
    }

    #[Route('/dashboard/inteligencia/pastas-para-tarefa', name: 'dashboard_inteligencia_pastas_tarefa', methods: ['GET'])]
    public function buscar(Request $request): JsonResponse
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->tenantContext->getCurrentTenant();
        if ($tenant === null
            || !$this->permissionChecker->canAccessModule($usuario, $tenant, 'bi')
            || !$this->moduloTarefas->pode($usuario, $tenant)) {
            return $this->json(['erro' => 'Sem permissão para criar tarefa.'], Response::HTTP_FORBIDDEN);
        }

        return $this->json($this->buscarPastas->executar($tenant, $usuario, (string) $request->query->get('q', '')));
    }
}
