<?php

declare(strict_types=1);

namespace App\Master\Controller;

use App\Master\UseCase\ConsultarCustosUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Dados da seção "Custos" do Painel do Master, em JSON, só leitura (spec docs/specs/master-custos.md).
 * `mes` no formato AAAA-MM (outro valor cai no mês atual).
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
final class CustosMasterDadosController extends AbstractController
{
    #[Route('/master/dados/custos', name: 'master_dados_custos', methods: ['GET'])]
    public function __invoke(Request $request, ConsultarCustosUseCase $useCase): JsonResponse
    {
        return $this->json($useCase->executar($request->query->has('mes') ? (string) $request->query->get('mes') : null));
    }
}
