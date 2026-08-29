<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Entity\Auth\User;
use App\Pasta\DTO\PastaPagamentosOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaPagamentoRepository;
use App\Pasta\UseCase\AlternarQuitacaoDoPagamentoUseCase;
use App\Pasta\UseCase\ExcluirPagamentoDaPastaUseCase;
use App\Pasta\UseCase\RegistrarPagamentoDaPastaUseCase;
use App\Service\PermissionChecker;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pagamentos a receber da pasta — o card Pagamentos do trilho da aba
 * Financeiro. Todas as rotas respondem JSON, no padrão da tela:
 * permissão → CSRF → UseCase → JSON com os totais recalculados.
 *
 * Os totais voltam em TODA resposta que muda alguma coisa. A alternativa seria
 * a tela recalcular por conta própria a partir da linha que mudou — e é assim
 * que dois números da mesma tela começam a discordar.
 */
#[Route('/pasta')]
final class PastaPagamentoController extends AbstractController
{
    public function __construct(
        private readonly PermissionChecker $permissionChecker,
        private readonly TenantContext $tenantContext,
        private readonly PastaPagamentoRepository $pagamentoRepository,
        private readonly RegistrarPagamentoDaPastaUseCase $registrarUseCase,
        private readonly AlternarQuitacaoDoPagamentoUseCase $alternarUseCase,
        private readonly ExcluirPagamentoDaPastaUseCase $excluirUseCase,
    ) {
    }

    #[Route('/{id}/pagamento', name: 'pasta_pagamento_registrar', methods: ['POST'])]
    public function registrar(Pasta $pasta, Request $request): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $pastaId     = (int) $pasta->getId();
        $tenant      = $this->tenantContext->getCurrentTenant();

        if ($tenant === null) {
            return $this->json(['erro' => 'Escritório não identificado.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->permissionChecker->canAccessResource($currentUser, $tenant, 'pasta', $pastaId, 'edit')) {
            return $this->json(['erro' => 'Sem permissão para editar esta pasta.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->isCsrfTokenValid('pasta_pagamento_' . $pastaId, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $pagamento = $this->registrarUseCase->executar(
                $pasta,
                $currentUser,
                $tenant,
                (string) $request->request->get('descricao', ''),
                (string) $request->request->get('valor', ''),
                (string) $request->request->get('vencimento', ''),
            );
        } catch (\InvalidArgumentException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'id'      => $pagamento->getId(),
            'resumo'  => $this->resumo($pasta),
        ], Response::HTTP_CREATED);
    }

    #[Route('/{id}/pagamento/{pagamentoId}/quitacao', name: 'pasta_pagamento_alternar_quitacao', methods: ['POST'])]
    public function alternarQuitacao(Pasta $pasta, int $pagamentoId, Request $request): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $pastaId     = (int) $pasta->getId();
        $tenant      = $this->tenantContext->getCurrentTenant();

        if ($tenant === null) {
            return $this->json(['erro' => 'Escritório não identificado.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->permissionChecker->canAccessResource($currentUser, $tenant, 'pasta', $pastaId, 'edit')) {
            return $this->json(['erro' => 'Sem permissão para editar esta pasta.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->isCsrfTokenValid('pasta_pagamento_quitacao_' . $pagamentoId, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        // 404, nunca 403: pagamento de outra pasta ou de outro escritório não
        // pode nem confirmar que existe.
        $pagamento = $this->pagamentoRepository->findByIdAndPastaAndTenant($pagamentoId, $pasta, $tenant);
        if ($pagamento === null) {
            return $this->json(['erro' => 'Pagamento não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        $this->alternarUseCase->executar($pagamento);

        return $this->json([
            'pago'   => $pagamento->estaPago(),
            'resumo' => $this->resumo($pasta),
        ]);
    }

    #[Route('/{id}/pagamento/{pagamentoId}/excluir', name: 'pasta_pagamento_excluir', methods: ['POST'])]
    public function excluir(Pasta $pasta, int $pagamentoId, Request $request): JsonResponse
    {
        /** @var User $currentUser */
        $currentUser = $this->getUser();
        $pastaId     = (int) $pasta->getId();
        $tenant      = $this->tenantContext->getCurrentTenant();

        if ($tenant === null) {
            return $this->json(['erro' => 'Escritório não identificado.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->permissionChecker->canAccessResource($currentUser, $tenant, 'pasta', $pastaId, 'edit')) {
            return $this->json(['erro' => 'Sem permissão para editar esta pasta.'], Response::HTTP_FORBIDDEN);
        }

        if (!$this->isCsrfTokenValid('pasta_pagamento_excluir_' . $pagamentoId, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        $pagamento = $this->pagamentoRepository->findByIdAndPastaAndTenant($pagamentoId, $pasta, $tenant);
        if ($pagamento === null) {
            return $this->json(['erro' => 'Pagamento não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        $this->excluirUseCase->executar($pagamento);

        return $this->json(['resumo' => $this->resumo($pasta)]);
    }

    /**
     * O corpo do card, relido do banco e RENDERIZADO PELO SERVIDOR, com o mesmo
     * partial da primeira carga. A tela troca o bloco inteiro por este.
     *
     * Devolver dados soltos e deixar o navegador remontar a lista duplicaria a
     * marcação em dois lugares — e é assim que o total e as linhas de uma mesma
     * tela começam a discordar. Os números continuam vindo daqui também, para
     * quem só precisa deles (a contagem do cabeçalho).
     *
     * @return array<string, mixed>
     */
    private function resumo(Pasta $pasta): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $lista  = $tenant !== null ? $this->pagamentoRepository->findByPasta($pasta, $tenant) : [];
        $saida  = PastaPagamentosOutput::montar($lista);

        return [
            'total'           => $saida->total,
            'quantidadePagos' => $saida->quantidadePagos,
            'recebido'        => $saida->recebidoFormatado,
            'previsto'        => $saida->previstoFormatado,
            'percentual'      => $saida->percentual,
            'html'            => $this->renderView('pasta/_financeiro_pagamentos.html.twig', [
                'pagamentos' => $saida,
            ]),
        ];
    }
}
