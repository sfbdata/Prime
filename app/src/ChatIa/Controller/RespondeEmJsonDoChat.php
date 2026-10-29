<?php

declare(strict_types=1);

namespace App\ChatIa\Controller;

use App\ChatIa\Exception\AnexoDoChatInvalidoException;
use App\ChatIa\Exception\ConversaDeChatNaoEncontradaException;
use App\ChatIa\Exception\ModeloDoChatInvalidoException;
use App\ChatIa\Exception\SemAceiteNoChatException;
use App\ChatIa\Exception\SemAcessoAoChatException;
use App\ChatIa\Exception\SemChaveNoChatException;
use App\ChatIa\Exception\TetoDiarioDoChatException;
use App\ChatIa\Exception\TextoDoChatInvalidoException;
use App\ChatIa\Exception\TituloDoChatInvalidoException;
use App\Central\Exception\PastaDaIaNaoEncontradaException;
use App\Entity\Auth\User;
use App\Inteligencia\Exception\ContextoBloqueadoException;
use App\Inteligencia\Exception\InteligenciaIndisponivelException;
use App\Inteligencia\Exception\MuitasSolicitacoesException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * O que os controllers do Chat I.A têm em comum: o erro JSON `{codigo, mensagem}` (contrato da spec), a tradução das
 * exceções de domínio em status HTTP, o CSRF do cabeçalho `X-CSRF-Token` e a leitura do corpo JSON. Exceção que não é
 * do contrato sobe (500), nunca vira uma resposta inventada.
 *
 * A classe que usa estende `AbstractController`.
 */
trait RespondeEmJsonDoChat
{
    /** @return JsonResponse|null a recusa se o token CSRF (`ajax`) não confere, ou nulo */
    private function semCsrf(Request $request): ?JsonResponse
    {
        if ($this->isCsrfTokenValid('ajax', (string) $request->headers->get('X-CSRF-Token', ''))) {
            return null;
        }

        return $this->erro('csrf', 'Sua sessão expirou. Recarregue a página e tente de novo.', Response::HTTP_FORBIDDEN);
    }

    /** @return JsonResponse|null a recusa 429 se o usuário estourou o limitador, ou nulo */
    private function semRitmo(RateLimiterFactoryInterface $limitador): ?JsonResponse
    {
        if ($limitador->create((string) $this->usuario()->getId())->consume()->isAccepted()) {
            return null;
        }

        return $this->erro('muitas_solicitacoes', 'Muitos pedidos em sequência. Aguarde um instante.', Response::HTTP_TOO_MANY_REQUESTS);
    }

    private function erro(string $codigo, string $mensagem, int $status): JsonResponse
    {
        $resposta = new JsonResponse(['codigo' => $codigo, 'mensagem' => $mensagem], $status);
        $resposta->headers->set('Cache-Control', 'private, no-store');

        return $resposta;
    }

    private function recusaDe(\Throwable $e): JsonResponse
    {
        return match (true) {
            $e instanceof SemAcessoAoChatException => $this->erro($e->codigo(), $e->getMessage(), Response::HTTP_FORBIDDEN),
            $e instanceof ConversaDeChatNaoEncontradaException => $this->erro($e->codigo(), $e->getMessage(), Response::HTTP_NOT_FOUND),
            $e instanceof PastaDaIaNaoEncontradaException => $this->erro('pasta_nao_encontrada', $e->getMessage(), Response::HTTP_NOT_FOUND),
            $e instanceof SemChaveNoChatException => $this->erro($e->codigo(), $e->getMessage(), Response::HTTP_SERVICE_UNAVAILABLE),
            $e instanceof SemAceiteNoChatException => $this->erro($e->codigo(), $e->getMessage(), Response::HTTP_CONFLICT),
            $e instanceof ContextoBloqueadoException => $this->erro($e->motivo(), $e->getMessage(), Response::HTTP_CONFLICT),
            $e instanceof TetoDiarioDoChatException => $this->erro($e->codigo(), $e->getMessage(), Response::HTTP_TOO_MANY_REQUESTS),
            $e instanceof MuitasSolicitacoesException => $this->erro('muitas_solicitacoes', 'Muitos pedidos em sequência. Aguarde um instante.', Response::HTTP_TOO_MANY_REQUESTS),
            $e instanceof InteligenciaIndisponivelException => $this->erro(
                $e->motivo->codigoDaRecusa(),
                $e->motivo->mensagem(),
                $e->motivo->ehLimiteDeCota() ? Response::HTTP_TOO_MANY_REQUESTS : Response::HTTP_CONFLICT,
            ),
            $e instanceof TextoDoChatInvalidoException,
            $e instanceof AnexoDoChatInvalidoException,
            $e instanceof ModeloDoChatInvalidoException,
            $e instanceof TituloDoChatInvalidoException => $this->erro($e->codigo(), $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY),
            default => throw $e,
        };
    }

    /** @return array<string, mixed> */
    private function corpoJson(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (\Throwable) {
            return [];
        }
    }

    private function usuario(): User
    {
        /** @var User $usuario */
        $usuario = $this->getUser();

        return $usuario;
    }
}
