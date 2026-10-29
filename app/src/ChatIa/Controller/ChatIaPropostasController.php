<?php

declare(strict_types=1);

namespace App\ChatIa\Controller;

use App\ChatIa\DTO\ConfirmarPropostaInput;
use App\ChatIa\UseCase\CancelarPropostaDeCadastroUseCase;
use App\ChatIa\UseCase\ConfirmarPropostaDeCadastroUseCase;
use App\Service\Tenant\TenantContext;
use App\Sync\Service\SincronizacaoPastaDispatcher;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Os cliques do cartão de proposta do Chat I.A (spec `chat-ia-cadastro.md`): `POST /chat-ia/propostas/{id}/confirmar`
 * (corpo opcional com os campos editados) e `POST /chat-ia/propostas/{id}/cancelar`. A I.A nunca grava: é este clique, de
 * quem está logado, que chama os UseCases de Cliente e Pasta. CSRF `ajax` no cabeçalho `X-CSRF-Token`, como o resto do chat.
 *
 * Resposta de sucesso: o cartão no estado novo (`PropostaOutput`). Erros `{codigo, mensagem}`: 404 proposta alheia, 409
 * encerrada/vencida/`duplicada`/`pasta_semelhante`, 403 sem permissão, 422 `proposta_invalida` com `erros` por campo.
 * O corpo e os valores nunca vão para log.
 */
#[Route('/chat-ia/propostas')]
final class ChatIaPropostasController extends AbstractController
{
    use RespondeEmJsonDoChat;

    public function __construct(
        private readonly TenantContext $tenantContext,
        // Interface (não a classe): dublê em teste.
        private readonly RateLimiterFactoryInterface $chatIaEscritaLimiter,
        private readonly ConfirmarPropostaDeCadastroUseCase $confirmar,
        private readonly CancelarPropostaDeCadastroUseCase $cancelar,
        private readonly SincronizacaoPastaDispatcher $sincronizacaoDaPasta,
    ) {
    }

    #[Route('/{id}/confirmar', name: 'chat_ia_proposta_confirmar', requirements: ['id' => '\d{1,9}'], methods: ['POST'])]
    public function confirmar(int $id, Request $request): JsonResponse
    {
        if (($recusa = $this->semCsrf($request)) !== null) {
            return $recusa;
        }
        if (($recusa = $this->semRitmo($this->chatIaEscritaLimiter)) !== null) {
            return $recusa;
        }
        $usuario = $this->usuario();
        $tenant = $this->tenantContext->getCurrentTenant();
        try {
            $confirmacao = $this->confirmar->executar($usuario, $tenant, $id, ConfirmarPropostaInput::deCorpo($this->corpoJson($request)));
        } catch (\Throwable $e) {
            return $this->recusaDe($e);
        }
        if ($confirmacao->pasta !== null && $tenant !== null) {
            // Como a tela de Nova pasta: com o Drive conectado, enfileira a sincronia (no-op sem Drive; nunca derruba o clique).
            $this->sincronizacaoDaPasta->despachar($confirmacao->pasta, $usuario, $tenant);
        }

        return $this->semCache(new JsonResponse($confirmacao->proposta->paraArray()));
    }

    #[Route('/{id}/cancelar', name: 'chat_ia_proposta_cancelar', requirements: ['id' => '\d{1,9}'], methods: ['POST'])]
    public function cancelar(int $id, Request $request): JsonResponse
    {
        if (($recusa = $this->semCsrf($request)) !== null) {
            return $recusa;
        }
        if (($recusa = $this->semRitmo($this->chatIaEscritaLimiter)) !== null) {
            return $recusa;
        }
        try {
            $proposta = $this->cancelar->executar($this->usuario(), $this->tenantContext->getCurrentTenant(), $id);
        } catch (\Throwable $e) {
            return $this->recusaDe($e);
        }

        return $this->semCache(new JsonResponse($proposta->paraArray()));
    }

    private function semCache(JsonResponse $resposta): JsonResponse
    {
        $resposta->headers->set('Cache-Control', 'private, no-store');

        return $resposta;
    }
}
