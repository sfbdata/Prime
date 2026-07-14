<?php

declare(strict_types=1);

namespace App\Cobranca\Controller;

use App\Cobranca\DTO\ConcluirAcaoInput;
use App\Cobranca\DTO\DefinirProximaAcaoInput;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\ProximaAcaoAtivaJaExisteException;
use App\Cobranca\Form\ConcluirAcaoType;
use App\Cobranca\Form\DefinirProximaAcaoType;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\ProximaAcaoRepository;
use App\Cobranca\UseCase\ConcluirAcaoUseCase;
use App\Cobranca\UseCase\DefinirProximaAcaoUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Mutações da próxima ação do Caso (Onda 8B): definir e concluir. Controller FINO — gate módulo +
 * capacidade `resources.cobranca.gerenciar`, resolução tenant-safe (anti-IDOR → 404), Form → UseCase,
 * PRG sempre.
 */
#[Route('/cobrancas')]
#[IsGranted('ROLE_USER')]
final class AcaoCobrancaController extends AbstractController
{
    use AutorizacaoCobranca;

    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly ProximaAcaoRepository $acaoRepository,
        private readonly DefinirProximaAcaoUseCase $definirProximaAcao,
        private readonly ConcluirAcaoUseCase $concluirAcao,
    ) {
    }

    #[Route('/casos/{id}/proxima-acao', name: 'cobranca_acao_definir', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function definir(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $caso = $this->casoRepository->findOneByIdDoTenant($id, $tenant);
        if ($caso === null) {
            throw $this->createNotFoundException('Caso de cobrança não encontrado.');
        }

        $input = new DefinirProximaAcaoInput();
        $input->casoId = $id;
        $form = $this->createForm(DefinirProximaAcaoType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->definirProximaAcao->executar($input, $tenant, $this->usuarioLogado());
                $this->addFlash('success', 'Próxima ação definida.');
            } catch (CasoEncerradoException | ProximaAcaoAtivaJaExisteException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            $this->flashErrosDoForm($form);
        }

        return $this->redirectToRoute('cobranca_objeto_show', ['id' => $this->objetoIdDoCaso($caso)]);
    }

    #[Route('/acoes/{id}/concluir', name: 'cobranca_acao_concluir', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function concluir(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $acao = $this->acaoRepository->findOneByIdDoTenant($id, $tenant);
        if ($acao === null) {
            throw $this->createNotFoundException('Ação não encontrada.');
        }
        $objetoId = $this->objetoIdDoCaso($acao->getCaso());

        $input = new ConcluirAcaoInput();
        $input->acaoId = $id;
        $form = $this->createForm(ConcluirAcaoType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->concluirAcao->executar($input, $tenant, $this->usuarioLogado());
                $this->addFlash('success', 'Ação concluída.');
            } catch (CasoEncerradoException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            $this->flashErrosDoForm($form);
        }

        return $this->redirectToRoute('cobranca_objeto_show', ['id' => $objetoId]);
    }
}
