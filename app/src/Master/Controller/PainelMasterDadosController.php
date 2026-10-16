<?php

declare(strict_types=1);

namespace App\Master\Controller;

use App\Master\UseCase\ConsultarAuditoriaUseCase;
use App\Master\UseCase\ConsultarContasUseCase;
use App\Master\UseCase\ConsultarUsuariosOabUseCase;
use App\Master\UseCase\ConsultarVisaoGeralUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Dados das seções do Painel do Master, em JSON, só leitura. Metadados (contagens, tamanhos, datas,
 * UF/cidade); a lista de contas leva o nome do escritório e do titular, como o desenho pede.
 */
#[IsGranted('ROLE_SUPER_ADMIN')]
#[Route('/master/dados')]
final class PainelMasterDadosController extends AbstractController
{
    #[Route('/visao-geral', name: 'master_dados_visao_geral', methods: ['GET'])]
    public function visaoGeral(ConsultarVisaoGeralUseCase $useCase): JsonResponse
    {
        return $this->json($useCase->executar());
    }

    #[Route('/contas', name: 'master_dados_contas', methods: ['GET'])]
    public function contas(ConsultarContasUseCase $useCase): JsonResponse
    {
        return $this->json(['contas' => $useCase->executar()]);
    }

    #[Route('/usuarios-oab', name: 'master_dados_usuarios_oab', methods: ['GET'])]
    public function usuariosOab(ConsultarUsuariosOabUseCase $useCase): JsonResponse
    {
        return $this->json($useCase->executar());
    }

    #[Route('/auditoria', name: 'master_dados_auditoria', methods: ['GET'])]
    public function auditoria(ConsultarAuditoriaUseCase $useCase): JsonResponse
    {
        return $this->json($useCase->executar());
    }
}
