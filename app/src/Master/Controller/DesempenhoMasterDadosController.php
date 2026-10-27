<?php

declare(strict_types=1);

namespace App\Master\Controller;

use App\Master\UseCase\ConsultarDesempenhoUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Dados da seção "Desempenho" do Painel do Master, em JSON, só leitura (spec docs/specs/master-desempenho.md).
 * `periodo` em dias: 1, 7 ou 30 (outro valor cai em 7).
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class DesempenhoMasterDadosController extends AbstractController
{
    #[Route('/master/dados/desempenho', name: 'master_dados_desempenho', methods: ['GET'])]
    public function __invoke(Request $request, ConsultarDesempenhoUseCase $useCase): JsonResponse
    {
        return $this->json($useCase->executar($request->query->getInt('periodo', ConsultarDesempenhoUseCase::PERIODO_PADRAO_EM_DIAS)));
    }
}
