<?php

declare(strict_types=1);

namespace App\Cobranca\Controller;

use App\Cobranca\DTO\ReconhecerValorAtualizadoInput;
use App\Cobranca\DTO\RegistrarObrigacaoInput;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Form\ReconhecerValorAtualizadoType;
use App\Cobranca\Form\RegistrarObrigacaoType;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\UseCase\ReconhecerValorAtualizadoUseCase;
use App\Cobranca\UseCase\RegistrarObrigacaoUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Mutações de Obrigações do Caso (Onda 8B). Controller FINO: gate módulo + capacidade
 * `resources.cobranca.gerenciar`, resolução tenant-safe por id (anti-IDOR → 404), Form → UseCase,
 * PRG sempre (erro de validação/domínio vira flash). Nenhuma regra de negócio aqui.
 */
#[Route('/cobrancas')]
#[IsGranted('ROLE_USER')]
final class ObrigacaoController extends AbstractController
{
    use AutorizacaoCobranca;

    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly ObrigacaoRepository $obrigacaoRepository,
        private readonly RegistrarObrigacaoUseCase $registrarObrigacao,
        private readonly ReconhecerValorAtualizadoUseCase $reconhecerValor,
    ) {
    }

    #[Route('/casos/{id}/obrigacoes', name: 'cobranca_obrigacao_registrar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function registrar(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $caso = $this->casoRepository->findOneByIdDoTenant($id, $tenant);
        if ($caso === null) {
            throw $this->createNotFoundException('Caso de cobrança não encontrado.');
        }

        $input = new RegistrarObrigacaoInput();
        $input->casoId = $id;
        $form = $this->createForm(RegistrarObrigacaoType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->registrarObrigacao->executar($input, $tenant, $this->usuarioLogado());
                $this->addFlash('success', 'Obrigação registrada.');
            } catch (CasoEncerradoException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            $this->flashErrosDoForm($form);
        }

        return $this->redirectToRoute('cobranca_caso_show', ['id' => $id]);
    }

    #[Route('/obrigacoes/{id}/reconhecer-valor', name: 'cobranca_obrigacao_reconhecer', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function reconhecerValor(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $obrigacao = $this->obrigacaoRepository->findOneByIdDoTenant($id, $tenant);
        if ($obrigacao === null) {
            throw $this->createNotFoundException('Obrigação não encontrada.');
        }
        $casoId = $obrigacao->getCaso()->getId();

        $input = new ReconhecerValorAtualizadoInput();
        $input->obrigacaoId = $id;
        $form = $this->createForm(ReconhecerValorAtualizadoType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->reconhecerValor->executar($input, $tenant, $this->usuarioLogado());
                $this->addFlash('success', 'Valor atualizado reconhecido.');
            } catch (CasoEncerradoException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            $this->flashErrosDoForm($form);
        }

        return $this->redirectToRoute('cobranca_caso_show', ['id' => $casoId]);
    }
}
