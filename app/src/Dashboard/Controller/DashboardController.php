<?php

declare(strict_types=1);

namespace App\Dashboard\Controller;

use App\Dashboard\UseCase\ObterDadosDashboardUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Repository\UserRepository;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/dashboard')]
final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PermissionChecker $permissionChecker,
        private readonly ObterDadosDashboardUseCase $obterDadosUseCase,
        private readonly UserRepository $userRepository,
    ) {}

    #[Route('', name: 'dashboard_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $tenant      = $this->assertAccess($currentUser);

        $filtros = [
            'data_de'     => (string) $request->query->get('data_de', ''),
            'data_ate'    => (string) $request->query->get('data_ate', ''),
            'responsavel' => (string) $request->query->get('responsavel', ''),
            'cargo'       => (string) $request->query->get('cargo', ''),
            // Coluna clicada no cabeçalho da tabela. A validação é do UseCase: chave que não
            // existe cai no padrão do painel em vez de quebrar a tela.
            'ordenar'     => (string) $request->query->get('ordenar', ''),
            'direcao'     => (string) $request->query->get('direcao', ''),
        ];

        $output = $this->obterDadosUseCase->executar($tenant, new \DateTimeImmutable(), $filtros);

        if ($request->isXmlHttpRequest()) {
            // `filtros` vai junto para o fragmento marcar a coluna ordenada — sem ele a seta
            // sumiria do cabeçalho a cada recarga por filtro.
            return $this->render('dashboard/_resultado.html.twig', [
                'dashboard' => $output,
                'filtros'   => $filtros,
            ]);
        }

        // Opções das facetas — só no render completo (a barra vive na casca). Passa arrays
        // simples ao template (convenção: nunca entidade Doctrine crua na view).
        $responsaveis = array_map(
            static fn (User $u): array => ['id' => $u->getId(), 'nome' => $u->getFullName()],
            $this->userRepository->findColaboradoresAtivosPorTenant($tenant),
        );
        $cargos = array_values(array_unique(array_filter($this->userRepository->findCargoPorColaboradores($tenant))));
        sort($cargos);

        return $this->render('dashboard/index.html.twig', [
            'dashboard'    => $output,
            'filtros'      => $filtros,
            'responsaveis' => $responsaveis,
            'cargos'       => $cargos,
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
