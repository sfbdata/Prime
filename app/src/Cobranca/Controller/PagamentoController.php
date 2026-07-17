<?php

declare(strict_types=1);

namespace App\Cobranca\Controller;

use App\Cobranca\DTO\CorrigirPagamentoInput;
use App\Cobranca\DTO\LinhaAlocacaoFifo;
use App\Cobranca\DTO\PreviaAlocacaoFifo;
use App\Cobranca\DTO\RegistrarPagamentoInput;
use App\Cobranca\Exception\CasoEncerradoException;
use App\Cobranca\Exception\CasoNaoEncontradoException;
use App\Cobranca\Exception\ObrigacaoDeOutroCasoException;
use App\Cobranca\Exception\ObrigacaoNaoEncontradaException;
use App\Cobranca\Exception\PagamentoExcedeSaldoException;
use App\Cobranca\Exception\PagamentoInconsistenteException;
use App\Cobranca\Exception\PagamentoNaoEncontradoException;
use App\Cobranca\Form\AcordoCriarType;
use App\Cobranca\Form\CorrigirPagamentoType;
use App\Cobranca\Form\RegistrarPagamentoType;
use App\Cobranca\Repository\CasoCobrancaRepository;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Repository\PagamentoRepository;
use App\Cobranca\Service\AutoAlocadorFifo;
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
 * acordo). Distribuição AUTOMÁTICA por FIFO por padrão, manual sob demanda (Ajuste 6); erros de
 * domínio (caso encerrado, obrigação de outro caso, pagamento inconsistente, excede o saldo) viram flash.
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
        private readonly AutoAlocadorFifo $autoAlocadorFifo,
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
            } catch (CasoNaoEncontradoException | CasoEncerradoException | ObrigacaoNaoEncontradaException | ObrigacaoDeOutroCasoException | PagamentoInconsistenteException | PagamentoExcedeSaldoException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            // B5: erro de campo reabre o modal com o digitado; CSRF (erro de raiz) segue com flash.
            $this->tratarFormInvalido($request, $form, $this->objetoIdDoCaso($caso), 'registrarPagamento', 'modalRegistrarPagamento', 'registrar_pagamento');
        }

        // Ajuste 10 (B4): o pagamento muda "O que já entrou" — é lá que o gestor confere o que registrou.
        return $this->redirect($this->generateUrl('cobranca_objeto_show', ['id' => $this->objetoIdDoCaso($caso)]) . '#secao-movimentos');
    }

    /**
     * Prévia ao vivo da divisão dívida/honorários + quebra FIFO de um pagamento a REGISTRAR (Ajuste 6).
     * GET read-only (sem CSRF); mesma regra de centavos do submit (fonte única). `valor` em centavos.
     */
    #[Route('/casos/{id}/pagamento-previa', name: 'cobranca_pagamento_previa', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function previaRegistrar(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.movimentacao_financeira');
        if ($tenant === null) {
            return $this->json(['erro' => 'Sem acesso.'], Response::HTTP_FORBIDDEN);
        }

        $caso = $this->casoRepository->findOneByIdDoTenant($id, $tenant);
        if ($caso === null) {
            throw $this->createNotFoundException('Caso de cobrança não encontrado.');
        }

        return $this->json($this->serializarPrevia(
            $this->autoAlocadorFifo->derivar($caso, max(0, $request->query->getInt('valor')), $tenant),
        ));
    }

    /**
     * Prévia ao vivo para a CORREÇÃO de um pagamento (Ajuste 6): exclui as alocações do próprio
     * pagamento da sala (igual ao submit). GET read-only; `valor` em centavos.
     */
    #[Route('/pagamentos/{id}/pagamento-previa', name: 'cobranca_pagamento_previa_corrigir', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function previaCorrigir(int $id, Request $request): Response
    {
        $tenant = $this->tenantComCapacidade('resources.cobranca.movimentacao_financeira');
        if ($tenant === null) {
            return $this->json(['erro' => 'Sem acesso.'], Response::HTTP_FORBIDDEN);
        }

        $pagamento = $this->pagamentoRepository->findOneByIdDoTenant($id, $tenant);
        $caso = $pagamento?->getCaso();
        if ($pagamento === null || $caso === null) {
            throw $this->createNotFoundException('Pagamento não encontrado.');
        }

        return $this->json($this->serializarPrevia(
            $this->autoAlocadorFifo->derivar($caso, max(0, $request->query->getInt('valor')), $tenant, $pagamento),
        ));
    }

    /** @return array<string, mixed> */
    private function serializarPrevia(PreviaAlocacaoFifo $previa): array
    {
        return [
            'valorPago' => $previa->valorPago,
            'divida' => $previa->valorDivida,
            'honorarios' => $previa->valorHonorarios,
            'saldoDisponivel' => $previa->saldoDisponivel,
            'excede' => $previa->excede,
            'excedeEm' => $previa->excedeEm,
            'alocacoes' => array_map(static fn (LinhaAlocacaoFifo $l): array => [
                'obrigacaoId' => $l->obrigacaoId,
                'descricao' => $l->descricao,
                'vencimento' => $l->vencimento->format('Y-m-d'),
                'valor' => $l->valor,
            ], $previa->linhas),
        ];
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
        // Defensivo: a JoinColumn é NOT NULL, mas o getter é nullable — sem caso não há o que corrigir.
        $caso = $pagamento->getCaso();
        if ($caso === null) {
            throw $this->createNotFoundException('Pagamento sem caso associado.');
        }
        $objetoId = $this->objetoIdDoCaso($caso);

        $input = new CorrigirPagamentoInput();
        $input->pagamentoId = $id;
        $opcoes = AcordoCriarType::opcoesObrigacoes($this->obrigacaoRepository->doCasoExigiveis($caso));
        $form = $this->createForm(CorrigirPagamentoType::class, $input, ['obrigacoes' => $opcoes]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->corrigirPagamento->executar($input, $tenant, $this->usuarioLogado());
                $this->addFlash('success', 'Pagamento corrigido.');
            } catch (PagamentoNaoEncontradoException | CasoEncerradoException | ObrigacaoNaoEncontradaException | ObrigacaoDeOutroCasoException | PagamentoInconsistenteException | PagamentoExcedeSaldoException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            $this->flashErrosDoForm($form);
        }

        // Ajuste 10 (B4): corrigir pagamento também mexe no extrato de movimentos.
        return $this->redirect($this->generateUrl('cobranca_objeto_show', ['id' => $objetoId]) . '#secao-movimentos');
    }
}
