<?php

declare(strict_types=1);

namespace App\Master\Controller;

use App\Master\UseCase\ConsultarAuditoriaUseCase;
use App\Master\UseCase\ConsultarContasUseCase;
use App\Master\UseCase\ConsultarPlanosUseCase;
use App\Master\UseCase\ConsultarUsuariosOabUseCase;
use App\Master\UseCase\ConsultarVisaoGeralUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Dados das seções do Painel do Master, em JSON, só leitura. Metadados (contagens, tamanhos, datas,
 * UF/cidade); a lista de contas leva o nome do escritório e do titular, como o desenho pede.
 *
 * Cada conta da lista leva o token CSRF dela (`token`), que o painel envia ao suspender ou reativar
 * ({@see ContasDoSistemaController}): token por conta, inútil para outra conta.
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

    #[Route('/mapa', name: 'master_dados_mapa', methods: ['GET'])]
    public function mapa(ConsultarVisaoGeralUseCase $useCase): JsonResponse
    {
        return $this->json($useCase->mapa());
    }

    #[Route('/contas', name: 'master_dados_contas', methods: ['GET'])]
    public function contas(
        ConsultarContasUseCase $useCase,
        CsrfTokenManagerInterface $csrf,
        #[Autowire('%tenant_carencia_purga_dias%')]
        int $carenciaPurgaDias,
    ): JsonResponse {
        $contas = array_map(
            static fn (array $c): array => $c + ['token' => $csrf->getToken(ContasDoSistemaController::PREFIXO_CSRF . $c['id'])->getValue()],
            $useCase->executar(),
        );

        // A carência vai junto para o modal de "Excluir conta" dizer o prazo configurado, não um número fixo.
        return $this->json(['contas' => $contas, 'carenciaPurgaDias' => $carenciaPurgaDias]);
    }

    /**
     * Planos do catálogo e faixas de alerta (spec perfis-1.2-planos-e-limites P1 e P6), com o token CSRF de
     * cada plano e o das faixas ({@see PlanosELimitesController}).
     */
    #[Route('/planos', name: 'master_dados_planos', methods: ['GET'])]
    public function planos(ConsultarPlanosUseCase $useCase, CsrfTokenManagerInterface $csrf): JsonResponse
    {
        $dados = $useCase->executar();
        $dados['planos'] = array_map(
            static fn (array $p): array => $p + ['token' => $csrf->getToken(PlanosELimitesController::PREFIXO_CSRF_PLANO . $p['codigo'])->getValue()],
            $dados['planos'],
        );

        return $this->json($dados + ['tokenFaixas' => $csrf->getToken(PlanosELimitesController::CSRF_FAIXAS)->getValue()]);
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
