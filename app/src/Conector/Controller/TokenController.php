<?php

declare(strict_types=1);

namespace App\Conector\Controller;

use App\Conector\DTO\PedidoDeTokenInput;
use App\Conector\Exception\ErroOAuth;
use App\Conector\Service\ServidorDeAutorizacao;
use App\Conector\UseCase\TrocarCodigoPorTokenUseCase;
use App\Conector\UseCase\TrocarRenovacaoUseCase;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * O `POST /oauth/token` do servidor de autorização do conector de I.A. (`docs/specs/mcp-remoto-oauth.md`, 3.2 e 3.3;
 * RFC 6749 3.2, 4.1.3, 5 e 6). Chamado pelo app de I.A., de servidor para servidor, sem sessão (`PUBLIC_ACCESS` no
 * `security.yaml`).
 *
 * - Corpo só `application/x-www-form-urlencoded`; outro formato é `invalid_request`.
 * - Cliente público: o `client_id` vem no corpo, sem segredo (`token_endpoint_auth_methods_supported: ["none"]`).
 * - Limite `conector_token` por `client_id` e IP (8); estourado, 429 com `Retry-After`.
 * - Toda resposta, de sucesso e de erro, é JSON com `Cache-Control: no-store` (RFC 6749 5.1). A de sucesso traz sempre
 *   o `scope` concedido (5.1, item 1). O erro sai pelo {@see ErroOAuth}: código da norma e texto fixo, nunca dado do
 *   pedido.
 */
final class TokenController extends AbstractController
{
    private const FORMATO_DO_CORPO = 'application/x-www-form-urlencoded';

    public function __construct(
        private readonly TrocarCodigoPorTokenUseCase $trocarCodigo,
        private readonly TrocarRenovacaoUseCase $trocarRenovacao,
        private readonly RateLimiterFactoryInterface $conectorTokenLimiter,
    ) {
    }

    #[Route('/oauth/token', name: ServidorDeAutorizacao::ROTA_TOKEN, methods: ['POST'])]
    public function trocar(Request $request): JsonResponse
    {
        $limite = $this->conectorTokenLimiter->create($this->chaveDoLimite($request))->consume();
        if (!$limite->isAccepted()) {
            $resposta = $this->erro(ErroOAuth::invalidRequest('Muitas tentativas. Tente de novo em instantes.'), 429);
            $resposta->headers->set('Retry-After', (string) max(1, $limite->getRetryAfter()->getTimestamp() - time()));

            return $resposta;
        }

        try {
            $pedido = $this->lerPedido($request);
            $saida = match ($pedido->grantType) {
                PedidoDeTokenInput::GRANT_CODIGO => $this->trocarCodigo->executar($pedido),
                PedidoDeTokenInput::GRANT_RENOVACAO => $this->trocarRenovacao->executar($pedido),
                null => throw ErroOAuth::invalidRequest(),
                default => throw ErroOAuth::unsupportedGrantType(),
            };
        } catch (ErroOAuth $erro) {
            return $this->erro($erro);
        }

        return $this->semCache(new JsonResponse($saida->paraResposta()));
    }

    /** @throws ErroOAuth */
    private function lerPedido(Request $request): PedidoDeTokenInput
    {
        $tipo = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type', ''))[0]));
        if ($tipo !== self::FORMATO_DO_CORPO) {
            throw ErroOAuth::invalidRequest();
        }

        return PedidoDeTokenInput::deFormulario($request->request->all());
    }

    /** O app e o IP: o tráfego de um app vem de poucos IPs, e o par divide a cota entre os apps (8). */
    private function chaveDoLimite(Request $request): string
    {
        // all() e não get(): o get() lança quando o campo vem como lista, e aqui isso é só um pedido malformado.
        $clientId = $request->request->all()['client_id'] ?? null;

        return hash('sha256', \is_string($clientId) ? $clientId : '') . '|' . ($request->getClientIp() ?? '');
    }

    private function erro(ErroOAuth $erro, ?int $status = null): JsonResponse
    {
        return $this->semCache(new JsonResponse($erro->paraResposta(), $status ?? $erro->statusHttp()));
    }

    private function semCache(JsonResponse $resposta): JsonResponse
    {
        $resposta->headers->set('Cache-Control', 'no-store');
        $resposta->headers->set('Pragma', 'no-cache');

        return $resposta;
    }
}
