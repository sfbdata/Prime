<?php

declare(strict_types=1);

namespace App\Cobranca\Controller;

use App\Cobranca\DTO\CorrigirPagamentoInput;
use App\Cobranca\DTO\RegistrarPagamentoInput;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Exception\ObrigacaoDeOutroCasoException;
use App\Cobranca\Exception\ObrigacaoNaoEncontradaException;
use App\Cobranca\Exception\PagamentoInconsistenteException;
use App\Cobranca\Exception\PagamentoNaoEncontradoException;
use App\Cobranca\Form\AcordoCriarType;
use App\Cobranca\Form\CorrigirPagamentoType;
use App\Cobranca\Form\RegistrarPagamentoType;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Repository\PagamentoRepository;
use App\Cobranca\UseCase\CorrigirPagamentoUseCase;
use App\Cobranca\UseCase\RegistrarPagamentoUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Mutações financeiras de Pagamento do Caso (Onda 8B-D): registrar e corrigir. Controller FINO — gate
 * módulo + capacidade `resources.cobranca.movimentacao_financeira`, resolução tenant-safe (anti-IDOR →
 * 404), Form → UseCase, PRG sempre. As obrigações das alocações são escopadas ao caso (mesmo padrão do
 * acordo). Alocação MANUAL explícita; erros de domínio (caso encerrado, obrigação de outro caso,
 * pagamento inconsistente) viram flash.
 */
#[Route('/cobrancas')]
#[IsGranted('ROLE_USER')]
final class PagamentoController extends AbstractController
{
    use AutorizacaoCobranca;

    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly CasoCobrancaRepository $casoRepository,
        private readonly PagamentoRepository $pagamentoRepository,
        private readonly ObrigacaoRepository $obrigacaoRepository,
        private readonly RegistrarPagamentoUseCase $registrarPagamento,
        private readonly CorrigirPagamentoUseCase $corrigirPagamento,
    ) {
    }

    #[Route('/casos/{id}/pagamentos', name: 'cobranca_pagamento_registrar', methods: ['POST'], requirements: ['id' => '\d+'])]
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

        $input = new RegistrarPagamentoInput();
        $input->casoId = $id;
        $opcoes = AcordoCriarType::opcoesObrigacoes($this->obrigacaoRepository->doCasoExigiveis($caso));
        $form = $this->createForm(RegistrarPagamentoType::class, $input, ['obrigacoes' => $opcoes]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->registrarPagamento->executar($input, $tenant, $this->usuarioLogado());
                $this->addFlash('success', 'Pagamento registrado.');
            } catch (CasoNaoEncontradoException | CasoEncerradoException | ObrigacaoNaoEncontradaException | ObrigacaoDeOutroCasoException | PagamentoInconsistenteException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            $this->flashErrosDoForm($form);
        }

        return $this->redirectToRoute('cobranca_caso_show', ['id' => $id]);
    }

    #[Route('/pagamentos/{id}/corrigir', name: 'cobranca_pagamento_corrigir', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function corrigir(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.movimentacao_financeira');
        if ($tenant === null) {
            return $this->semAcesso();
        }

        $pagamento = $this->pagamentoRepository->findOneByIdDoTenant($id, $tenant);
        if ($pagamento === null) {
            throw $this->createNotFoundException('Pagamento não encontrado.');
        }
        $caso = $pagamento->getCaso();
        $casoId = $caso->getId();

        $input = new CorrigirPagamentoInput();
        $input->pagamentoId = $id;
        $opcoes = AcordoCriarType::opcoesObrigacoes($this->obrigacaoRepository->doCasoExigiveis($caso));
        $form = $this->createForm(CorrigirPagamentoType::class, $input, ['obrigacoes' => $opcoes]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->corrigirPagamento->executar($input, $tenant, $this->usuarioLogado());
                $this->addFlash('success', 'Pagamento corrigido.');
            } catch (PagamentoNaoEncontradoException | CasoEncerradoException | ObrigacaoNaoEncontradaException | ObrigacaoDeOutroCasoException | PagamentoInconsistenteException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            $this->flashErrosDoForm($form);
        }

        return $this->redirectToRoute('cobranca_caso_show', ['id' => $casoId]);
    }
}
