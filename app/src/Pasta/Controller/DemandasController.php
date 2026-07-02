<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Entity\Auth\User;
use App\Pasta\UseCase\ListarMinhasDemandasUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DemandasController extends AbstractController
{
    private const PER_PAGE = 25;

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

        $filtros = [
            'busca'      => trim((string) $request->query->get('busca', '')),
            'status'     => (string) $request->query->get('status', ''),
            'prioridade' => (string) $request->query->get('prioridade', ''),
            'data_de'    => (string) $request->query->get('data_de', ''),
            'data_ate'   => (string) $request->query->get('data_ate', ''),
        ];
        // Sem ordenação explícita, mantém o comportamento histórico: urgentes primeiro.
        $ordenar = (string) $request->query->get('ordenar', '') ?: 'prioridade';
        $direcao = strtolower((string) $request->query->get('direcao', 'desc')) === 'asc' ? 'asc' : 'desc';
        $pagina  = max(1, (int) $request->query->get('page', 1));

        $resultado    = $this->listarMinhasDemandas->executar($usuario, $tenant, $filtros, $pagina, self::PER_PAGE, $ordenar, $direcao);
        $totalPaginas = max(1, (int) ceil($resultado['total'] / self::PER_PAGE));

        $dados = [
            'pastas'        => $resultado['pastas'],
            'total'         => $resultado['total'],
            'pagina'        => $pagina,
            'total_paginas' => $totalPaginas,
            'filtros'       => $filtros + ['ordenar' => $ordenar, 'direcao' => $direcao],
        ];

        if ($request->isXmlHttpRequest()) {
            return $this->render('pasta/demandas/_resultado.html.twig', $dados);
        }

        return $this->render('pasta/demandas.html.twig', $dados);
    }
}
