<?php

declare(strict_types=1);

namespace App\Conector\Mcp;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Responde ao `OPTIONS` antes do portão e do limite, com o mesmo 204 que o transporte do SDK daria. O
 * `CorsMiddleware` do SDK não encerra o preflight (ele chama o resto da cadeia e só decora a resposta) e o
 * `AuthorizationMiddleware` deixa o `OPTIONS` passar sem token; sem este passo o portão, que recusa toda requisição
 * sem token aceito, devolveria 503 ao preflight. Nada é despachado: o `OPTIONS` não chega ao servidor MCP.
 */
final class PreflightSemPortaoMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly ResponseFactoryInterface $respostas)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ('OPTIONS' === $request->getMethod()) {
            return $this->respostas->createResponse(204);
        }

        return $handler->handle($request);
    }
}
