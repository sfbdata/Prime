<?php

declare(strict_types=1);

namespace App\Cobranca\Controller;

use App\Cobranca\DTO\GerarRevisaoInput;
use App\Cobranca\DTO\ResolverRevisaoInput;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\RevisaoJaResolvidaException;
use App\Cobranca\Form\GerarRevisaoType;
use App\Cobranca\Form\ResolverRevisaoType;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\RevisaoPessoaCobradaRepository;
use App\Cobranca\UseCase\GerarRevisaoUseCase;
use App\Cobranca\UseCase\ResolverRevisaoUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Mutações da revisão de pessoa cobrada (Onda 8B): gerar e resolver. Controller FINO — gate módulo +
 * capacidade `resources.cobranca.gerenciar`, resolução tenant-safe (anti-IDOR → 404), Form → UseCase,
 * PRG sempre.
 */
#[Route('/cobrancas')]
#[IsGranted('ROLE_USER')]
final class RevisaoCobrancaController extends AbstractController
{
    use AutorizacaoCobranca;

    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly RevisaoPessoaCobradaRepository $revisaoRepository,
        private readonly GerarRevisaoUseCase $gerarRevisao,
        private readonly ResolverRevisaoUseCase $resolverRevisao,
    ) {
    }

    #[Route('/casos/{id}/revisoes', name: 'cobranca_revisao_gerar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function gerar(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $caso = $this->casoRepository->findOneByIdDoTenant($id, $tenant);
        if ($caso === null) {
            throw $this->createNotFoundException('Caso de cobrança não encontrado.');
        }

        $input = new GerarRevisaoInput();
        $input->casoId = $id;
        $form = $this->createForm(GerarRevisaoType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->gerarRevisao->executar($input, $tenant, $this->usuarioLogado());
                $this->addFlash('success', 'Revisão de pessoa cobrada aberta.');
            } catch (CasoEncerradoException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            $this->flashErrosDoForm($form);
        }

        return $this->redirectToRoute('cobranca_caso_show', ['id' => $id]);
    }

    #[Route('/revisoes/{id}/resolver', name: 'cobranca_revisao_resolver', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function resolver(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $revisao = $this->revisaoRepository->findOneByIdDoTenant($id, $tenant);
        if ($revisao === null) {
            throw $this->createNotFoundException('Revisão não encontrada.');
        }
        $casoId = $revisao->getCaso()->getId();

        $input = new ResolverRevisaoInput();
        $input->revisaoId = $id;
        $form = $this->createForm(ResolverRevisaoType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->resolverRevisao->executar($input, $tenant, $this->usuarioLogado());
                $this->addFlash('success', 'Revisão resolvida.');
            } catch (RevisaoJaResolvidaException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            $this->flashErrosDoForm($form);
        }

        return $this->redirectToRoute('cobranca_caso_show', ['id' => $casoId]);
    }
}
