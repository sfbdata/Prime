<?php

declare(strict_types=1);

namespace App\Cobranca\Controller;

use App\Cobranca\Repository\CarteiraRepository;
use App\Cobranca\UseCase\ListarCarteirasUseCase;
use App\Cobranca\UseCase\MontarVisaoCarteiraUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Camada HTTP das Carteiras de Cobrança (Etapa 8). Controller FINO: só glue — gate de módulo,
 * resolução tenant-safe por id (anti-IDOR), delegação aos UseCases de leitura e render de Output
 * DTOs. Nenhuma regra de negócio aqui. A lista reusa a máquina de filtros do Expediente (fragmento
 * XHR + página cheia na mesma rota).
 */
#[Route('/cobrancas')]
#[IsGranted('ROLE_USER')]
final class CarteiraController extends AbstractController
{
    use AutorizacaoCobranca;

    private const POR_PAGINA = 20;

    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly CarteiraRepository $carteiraRepository,
        private readonly ListarCarteirasUseCase $listarCarteiras,
        private readonly MontarVisaoCarteiraUseCase $montarVisaoCarteira,
    ) {
    }

    #[Route('', name: 'cobranca_carteira_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $tenant = $this->tenantComModulo();
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $filtros = [
            'busca' => trim((string) $request->query->get('busca', '')),
            'modo' => (string) $request->query->get('modo', ''),
        ];
        $ordenar = (string) $request->query->get('ordenar', '') ?: 'nome';
        $direcao = strtolower((string) $request->query->get('direcao', 'asc')) === 'desc' ? 'desc' : 'asc';
        $pagina = max(1, (int) $request->query->get('page', 1));

        $resultado = $this->listarCarteiras->executar($tenant, $filtros, $pagina, self::POR_PAGINA, $ordenar, $direcao);
        $total = $resultado['total'];

        $dados = [
            'carteiras' => $resultado['itens'],
            'total' => $total,
            'pagina' => $pagina,
            'total_paginas' => (int) max(1, ceil($total / self::POR_PAGINA)),
            'filtros' => $filtros + ['ordenar' => $ordenar, 'direcao' => $direcao],
        ];

        if ($request->isXmlHttpRequest()) {
            return $this->render('cobranca/carteira/_resultado.html.twig', $dados);
        }

        return $this->render('cobranca/carteira/index.html.twig', $dados);
    }

    #[Route('/carteiras/{id}', name: 'cobranca_carteira_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id, Request $request): Response
    {
        $tenant = $this->tenantComModulo();
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $carteira = $this->carteiraRepository->findOneByIdDoTenant($id, $tenant);
        if ($carteira === null) {
            throw $this->createNotFoundException('Carteira de cobrança não encontrada.');
        }

        $visao = $this->montarVisaoCarteira->executar($carteira);

        return $this->render('cobranca/carteira/show.html.twig', [
            'carteira' => $visao['carteira'],
            'casos' => $visao['casos'],
        ]);
    }
}
