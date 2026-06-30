<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Entity\Auth\User;
use App\Pasta\Entity\PrioridadePasta;
use App\Pasta\UseCase\ListarMinhasDemandasUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DemandasController extends AbstractController
{
    public function __construct(
        private readonly ListarMinhasDemandasUseCase $listarMinhasDemandas,
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
    ) {}

    #[Route('/demandas', name: 'demandas_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        /** @var User $usuario */
        $usuario = $this->getUser();
        $tenant  = $this->tenantContext->getCurrentTenant();

        // Demandas é uma listagem do domínio Pasta: gateada pelo módulo, como index/criar (B7).
        // O dado já é escopado por tenant+responsável no UseCase; isto alinha a porta de entrada.
        if (!$this->permissionChecker->canAccessModule($usuario, $tenant, 'pastas')) {
            $this->addFlash('warning', 'Você não tem permissão para acessar as demandas.');

            return $this->redirectToRoute('homepage');
        }

        $cliente    = $request->query->get('cliente', '') ?: null;
        $prioridade = $request->query->get('prioridade', '') ?: null;

        $pastas = $this->listarMinhasDemandas->executar($usuario, $tenant, $cliente, $prioridade);

        return $this->render('pasta/demandas.html.twig', [
            'pastas'      => $pastas,
            'filtros'     => [
                'cliente'    => $cliente ?? '',
                'prioridade' => $prioridade ?? '',
            ],
            'prioridades' => PrioridadePasta::cases(),
        ]);
    }
}
