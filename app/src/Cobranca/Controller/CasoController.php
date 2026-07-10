<?php

declare(strict_types=1);

namespace App\Cobranca\Controller;

use App\Cobranca\Repository\CarteiraRepository;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\UseCase\ListarCasosUseCase;
use App\Cobranca\UseCase\MontarDetalheCasoUseCase;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Camada HTTP dos Casos de Cobrança (Etapa 8). Controller FINO: gate de módulo, resolução
 * tenant-safe por id (anti-IDOR), delegação aos UseCases de leitura, render de Output DTOs. A lista
 * reusa a máquina de filtros do Expediente; o detalhe é a tela central (SPEC §9/§26). Ações de
 * escrita (formulários) entram na Onda 8B — aqui é só leitura/navegação.
 */
#[Route('/cobrancas/casos')]
#[IsGranted('ROLE_USER')]
final class CasoController extends AbstractController
{
    private const POR_PAGINA = 20;

    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly CarteiraRepository $carteiraRepository,
        private readonly ListarCasosUseCase $listarCasos,
        private readonly MontarDetalheCasoUseCase $montarDetalheCaso,
    ) {
    }

    #[Route('', name: 'cobranca_caso_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        [$usuario, $tenant] = $this->contexto();
        if (!$this->permissionChecker->canAccessModule($usuario, $tenant, 'cobrancas')) {
            return $this->semAcesso();
        }

        $filtros = [
            'busca' => trim((string) $request->query->get('busca', '')),
            'status' => (string) $request->query->get('status', ''),
            'carteira' => (string) $request->query->get('carteira', ''),
        ];
        $ordenar = (string) $request->query->get('ordenar', '') ?: 'atualizacao';
        $direcao = strtolower((string) $request->query->get('direcao', 'desc')) === 'asc' ? 'asc' : 'desc';
        $pagina = max(1, (int) $request->query->get('page', 1));

        $resultado = $this->listarCasos->executar($tenant, $filtros, $pagina, self::POR_PAGINA, $ordenar, $direcao);
        $total = $resultado['total'];

        $dados = [
            'casos' => $resultado['itens'],
            'total' => $total,
            'pagina' => $pagina,
            'total_paginas' => (int) max(1, ceil($total / self::POR_PAGINA)),
            'filtros' => $filtros + ['ordenar' => $ordenar, 'direcao' => $direcao],
        ];

        if ($request->isXmlHttpRequest()) {
            return $this->render('cobranca/caso/_resultado.html.twig', $dados);
        }

        // A barra de facetas só existe na página cheia (fica fora do fragmento XHR).
        $dados['carteiras'] = $this->carteiraRepository->opcoesFacetaDoTenant($tenant);

        return $this->render('cobranca/caso/index.html.twig', $dados);
    }

    #[Route('/{id}', name: 'cobranca_caso_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        [$usuario, $tenant] = $this->contexto();
        if (!$this->permissionChecker->canAccessModule($usuario, $tenant, 'cobrancas')) {
            return $this->semAcesso();
        }

        $caso = $this->casoRepository->findOneByIdDoTenant($id, $tenant);
        if ($caso === null) {
            throw $this->createNotFoundException('Caso de cobrança não encontrado.');
        }

        return $this->render('cobranca/caso/show.html.twig', [
            'caso' => $this->montarDetalheCaso->executar($caso),
        ]);
    }

    /**
     * @return array{0: User, 1: ?Tenant}
     */
    private function contexto(): array
    {
        /** @var User $usuario */
        $usuario = $this->getUser();

        return [$usuario, $this->tenantContext->getCurrentTenant()];
    }

    private function semAcesso(): Response
    {
        $this->addFlash('warning', 'Você não tem permissão para acessar o módulo de Cobranças.');

        return $this->redirectToRoute('homepage');
    }
}
