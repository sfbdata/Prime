<?php

declare(strict_types=1);

namespace App\Pasta\Controller;

use App\Entity\Auth\User;
use App\Pasta\DTO\ParcelamentoDaPastaInput;
use App\Pasta\DTO\PastaPagamentosOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaPagamentoRepository;
use App\Pasta\UseCase\AlternarQuitacaoDoPagamentoUseCase;
use App\Pasta\UseCase\CorrigirValorDoPagamentoUseCase;
use App\Pasta\UseCase\ExcluirPagamentoDaPastaUseCase;
use App\Pasta\UseCase\RegistrarPagamentoDaPastaUseCase;
use App\Pasta\UseCase\RegistrarParcelamentoDaPastaUseCase;
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
        private readonly CorrigirValorDoPagamentoUseCase $corrigirValorUseCase,
        private readonly RegistrarParcelamentoDaPastaUseCase $parcelamentoUseCase,
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

    /**
     * "Adicionar pagamento" do desenho 1.2.3 (dc L.1928-2012): entrada, N
     * parcelas e juros, gravados de uma vez. O POST leva os CAMPOS do modal; a
     * conta das parcelas é refeita no UseCase — o que a prévia do navegador
     * mostrou não é gravado, é recalculado.
     *
     * Mesma ordem e mesmo CSRF do lançamento avulso (o gesto é o mesmo: lançar
     * na pasta): permissão de editar → CSRF → UseCase. Pasta de outro escritório
     * nem resolve (TenantFilter → 404); pasta excluída é recusada antes pelo
     * `PastaSomenteLeituraListener`. Erro de campo → 422, sem gravar nada.
     */
    #[Route('/{id}/pagamento/parcelamento', name: 'pasta_pagamento_parcelar', methods: ['POST'])]
    public function parcelar(Pasta $pasta, Request $request): JsonResponse
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

        $campo = static fn (string $nome): string => (string) $request->request->get($nome, '');

        // "Nenhuma operação pode registrar recebimento financeiro que não
        // ocorreu" (decisão do dono, 07/10/2026). O modal não tem mais o
        // controle "Entrada já recebida hoje"; quem ainda mandar `entradaPaga=1`
        // (aba aberta antes do deploy, POST forjado) é RECUSADO com 422, sem
        // gravar nada — ignorar em silêncio deixaria a pessoa achando que a
        // entrada ficou quitada. Só `0` ou ausente/vazio seguem (pendente);
        // qualquer outro valor (`1`, `true`, `on`…) é recusado.
        if (!\in_array($campo('entradaPaga'), ['', '0'], true)) {
            return $this->json([
                'erro' => 'A entrada não pode ser lançada como recebida. Lance o parcelamento e use "Marcar como recebido" quando o dinheiro entrar.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $saida = $this->parcelamentoUseCase->executar($pasta, $currentUser, $tenant, new ParcelamentoDaPastaInput(
                tipo: $campo('tipo'),
                base: $campo('base'),
                valorTotal: $campo('total'),
                percentual: $campo('percentual'),
                entrada: $campo('entrada'),
                parcelas: $campo('parcelas'),
                primeiroVencimento: $campo('vencimento'),
                comJuros: $campo('juros') === '1',
                taxaMensal: $campo('taxa'),
                descricao: $campo('descricao'),
            ));
        } catch (\DomainException) {
            return $this->json(['erro' => 'Pasta não encontrada.'], Response::HTTP_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'ids'        => $saida->ids,
            'quantidade' => $saida->quantidade,
            'resumo'     => $this->resumo($pasta),
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
     * "Editar ou corrigir valores" (desenho 1.2.3, dc 3443-3447): troca o valor
     * de UM lançamento. O histórico (quem, quando, de quanto para quanto) não é
     * gravado aqui — sai do `audit_log` que o flush do UseCase alimenta.
     *
     * Mesma ordem das outras rotas do card: permissão de editar a pasta → CSRF
     * do pagamento → posse (404) → UseCase. O POST leva `valor` (o novo) e
     * `valorAnterior` (o que a tela mostrou); se este não for mais o do banco,
     * 409 sem gravar — outra pessoa corrigiu no meio do caminho. Pasta
     * excluída nem chega aqui: o `PastaSomenteLeituraListener` recusa todo POST que receba a pasta.
     */
    #[Route('/{id}/pagamento/{pagamentoId}/valor', name: 'pasta_pagamento_corrigir_valor', methods: ['POST'])]
    public function corrigirValor(Pasta $pasta, int $pagamentoId, Request $request): JsonResponse
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

        if (!$this->isCsrfTokenValid('pasta_pagamento_corrigir_' . $pagamentoId, (string) $request->request->get('_token'))) {
            return $this->json(['erro' => 'Token de segurança inválido.'], Response::HTTP_FORBIDDEN);
        }

        // 404, nunca 403: pagamento de outra pasta ou de outro escritório não
        // pode nem confirmar que existe.
        $pagamento = $this->pagamentoRepository->findByIdAndPastaAndTenant($pagamentoId, $pasta, $tenant);
        if ($pagamento === null) {
            return $this->json(['erro' => 'Pagamento não encontrado.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $alterado = $this->corrigirValorUseCase->executar(
                $pagamento,
                $pasta,
                $tenant,
                (string) $request->request->get('valor', ''),
                (string) $request->request->get('valorAnterior', ''),
            );
        } catch (\DomainException) {
            return $this->json(['erro' => 'Pagamento não encontrado.'], Response::HTTP_NOT_FOUND);
        } catch (\UnexpectedValueException $e) {
            // Outra pessoa corrigiu antes: nada foi gravado. Vai junto o card
            // relido, para a tela passar a mostrar o valor que vale agora.
            return $this->json([
                'erro'       => $e->getMessage(),
                'valorAtual' => str_replace('.', ',', $pagamento->getValor()),
                'resumo'     => $this->resumo($pasta),
            ], Response::HTTP_CONFLICT);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['erro' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json([
            'alterado' => $alterado,
            'resumo'   => $this->resumo($pasta),
        ]);
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
        $saida  = PastaPagamentosOutput::montar(
            $lista,
            null,
            $tenant !== null ? $this->pagamentoRepository->correcoesDeValor($lista, $tenant) : [],
        );

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
