<?php

declare(strict_types=1);

namespace App\Cobranca\Controller;

use App\Cobranca\DTO\RegistrarLiquidacaoInput;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Form\RegistrarLiquidacaoType;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\UseCase\RegistrarLiquidacaoUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Mutação financeira de Liquidação não monetária do Caso (Onda 8B-D). Controller FINO — gate módulo +
 * capacidade `resources.cobranca.movimentacao_financeira`, resolução tenant-safe (anti-IDOR → 404),
 * Form → UseCase, PRG sempre. Só formas NÃO monetárias (dinheiro é Pagamento). O que reduz o saldo é o
 * valor reconhecido; caso encerrado não recebe liquidação.
 */
#[Route('/cobrancas')]
#[IsGranted('ROLE_USER')]
final class LiquidacaoController extends AbstractController
{
    use AutorizacaoCobranca;

    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly RegistrarLiquidacaoUseCase $registrarLiquidacao,
    ) {
    }

    #[Route('/casos/{id}/liquidacoes', name: 'cobranca_liquidacao_registrar', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function registrar(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.movimentacao_financeira');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $caso = $this->casoRepository->findOneByIdDoTenant($id, $tenant);
        if ($caso === null) {
            throw $this->createNotFoundException('Caso de cobrança não encontrado.');
        }

        $input = new RegistrarLiquidacaoInput();
        $input->casoId = $id;
        $form = $this->createForm(RegistrarLiquidacaoType::class, $input);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->registrarLiquidacao->executar($input, $tenant, $this->usuarioLogado());
                $this->addFlash('success', 'Liquidação registrada.');
            } catch (CasoNaoEncontradoException | CasoEncerradoException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            $this->flashErrosDoForm($form);
        }

        // Ajuste 10 (B4): volta olhando "O que já entrou" — a liquidação é movimento, não dívida. O
        // `redirectToRoute` não monta fragmento; a URL é gerada e o `#` concatenado.
        return $this->redirect($this->generateUrl('cobranca_objeto_show', ['id' => $this->objetoIdDoCaso($caso)]) . '#secao-movimentos');
    }
}
