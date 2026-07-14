<?php

declare(strict_types=1);

namespace App\Cobranca\Controller;

use App\Cobranca\DTO\EditarObrigacaoInput;
use App\Cobranca\DTO\RegistrarObrigacaoInput;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\ObrigacaoDeAcordoException;
use App\Cobranca\Exception\ValorAbaixoDoAlocadoException;
use App\Cobranca\Form\EditarObrigacaoType;
use App\Cobranca\Form\RegistrarObrigacaoType;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\UseCase\EditarObrigacaoUseCase;
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
        private readonly EditarObrigacaoUseCase $editarObrigacao,
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

        return $this->redirectToRoute('cobranca_objeto_show', ['id' => $this->objetoIdDoCaso($caso)]);
    }

    #[Route('/obrigacoes/{id}/editar', name: 'cobranca_obrigacao_editar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function editar(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.gerenciar');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $obrigacao = $this->obrigacaoRepository->findOneByIdDoTenant($id, $tenant);
        if ($obrigacao === null) {
            throw $this->createNotFoundException('Obrigação não encontrada.');
        }
        $objetoId = $this->objetoIdDoCaso($obrigacao->getCaso());

        $input = new EditarObrigacaoInput();
        $input->obrigacaoId = $id;
        $form = $this->createForm(EditarObrigacaoType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->editarObrigacao->executar($input, $tenant, $this->usuarioLogado());
                $this->addFlash('success', 'Obrigação corrigida.');
            } catch (CasoEncerradoException | ObrigacaoDeAcordoException | ValorAbaixoDoAlocadoException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            $this->flashErrosDoForm($form);
        }

        return $this->redirectToRoute('cobranca_objeto_show', ['id' => $objetoId]);
    }
}
