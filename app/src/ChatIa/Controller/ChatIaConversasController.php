<?php

declare(strict_types=1);

namespace App\ChatIa\Controller;

use App\ChatIa\DTO\AtualizarConversaInput;
use App\ChatIa\DTO\ConversaNaListaOutput;
use App\ChatIa\DTO\PastaCitavelOutput;
use App\ChatIa\UseCase\AtualizarConversaDoChatUseCase;
use App\ChatIa\UseCase\BuscarPastasParaOChatUseCase;
use App\ChatIa\UseCase\CriarConversaDoChatUseCase;
use App\ChatIa\UseCase\ExcluirConversaDoChatUseCase;
use App\ChatIa\UseCase\ListarConversasDoChatUseCase;
use App\ChatIa\UseCase\VerConversaDoChatUseCase;
use App\Service\Tenant\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * As rotas JSON das conversas do Chat I.A e da busca de pastas (contrato em `docs/specs/chat-pagina-inteira.md`). Todas
 * exigem login; vínculo ativo, módulo da I.A e POSSE da conversa são conferidos nos UseCases (conversa alheia = 404).
 * Mutações exigem o CSRF `ajax` no cabeçalho `X-CSRF-Token`. Erros: `{codigo, mensagem}`.
 */
#[Route('/chat-ia')]
final class ChatIaConversasController extends AbstractController
{
    use RespondeEmJsonDoChat;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ListarConversasDoChatUseCase $listar,
        private readonly CriarConversaDoChatUseCase $criar,
        private readonly VerConversaDoChatUseCase $ver,
        private readonly AtualizarConversaDoChatUseCase $atualizar,
        private readonly ExcluirConversaDoChatUseCase $excluir,
        private readonly BuscarPastasParaOChatUseCase $buscarPastas,
    ) {
    }

    #[Route('/conversas', name: 'chat_ia_conversas_listar', methods: ['GET'])]
    public function listar(Request $request): JsonResponse
    {
        try {
            $conversas = $this->listar->executar($this->usuario(), $this->tenantContext->getCurrentTenant(), (string) $request->query->get('q', ''));
        } catch (\Throwable $e) {
            return $this->recusaDe($e);
        }

        return $this->semCache(new JsonResponse(array_map(static fn (ConversaNaListaOutput $c): array => $c->paraArray(), $conversas)));
    }

    #[Route('/conversas', name: 'chat_ia_conversas_criar', methods: ['POST'])]
    public function criar(Request $request): JsonResponse
    {
        if (($recusa = $this->semCsrf($request)) !== null) {
            return $recusa;
        }
        try {
            $id = $this->criar->executar($this->usuario(), $this->tenantContext->getCurrentTenant());
        } catch (\Throwable $e) {
            return $this->recusaDe($e);
        }

        return new JsonResponse(['id' => $id], Response::HTTP_CREATED);
    }

    #[Route('/conversas/{id}', name: 'chat_ia_conversa_ver', requirements: ['id' => '\d{1,9}'], methods: ['GET'])]
    public function ver(int $id): JsonResponse
    {
        try {
            $conversa = $this->ver->executar($this->usuario(), $this->tenantContext->getCurrentTenant(), $id);
        } catch (\Throwable $e) {
            return $this->recusaDe($e);
        }

        return $this->semCache(new JsonResponse($conversa->paraArray()));
    }

    #[Route('/conversas/{id}', name: 'chat_ia_conversa_atualizar', requirements: ['id' => '\d{1,9}'], methods: ['PATCH'])]
    public function atualizar(int $id, Request $request): JsonResponse
    {
        if (($recusa = $this->semCsrf($request)) !== null) {
            return $recusa;
        }
        try {
            $this->atualizar->executar($this->usuario(), $this->tenantContext->getCurrentTenant(), $id, AtualizarConversaInput::deCorpo($this->corpoJson($request)));
        } catch (\Throwable $e) {
            return $this->recusaDe($e);
        }

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/conversas/{id}', name: 'chat_ia_conversa_excluir', requirements: ['id' => '\d{1,9}'], methods: ['DELETE'])]
    public function excluir(int $id, Request $request): JsonResponse
    {
        if (($recusa = $this->semCsrf($request)) !== null) {
            return $recusa;
        }
        try {
            $this->excluir->executar($this->usuario(), $this->tenantContext->getCurrentTenant(), $id);
        } catch (\Throwable $e) {
            return $this->recusaDe($e);
        }

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/pastas', name: 'chat_ia_pastas_buscar', methods: ['GET'])]
    public function pastas(Request $request): JsonResponse
    {
        try {
            $pastas = $this->buscarPastas->executar($this->usuario(), $this->tenantContext->getCurrentTenant(), (string) $request->query->get('q', ''));
        } catch (\Throwable $e) {
            return $this->recusaDe($e);
        }

        return $this->semCache(new JsonResponse(array_map(static fn (PastaCitavelOutput $p): array => $p->paraArray(), $pastas)));
    }

    private function semCache(JsonResponse $resposta): JsonResponse
    {
        $resposta->headers->set('Cache-Control', 'private, no-store');

        return $resposta;
    }
}
