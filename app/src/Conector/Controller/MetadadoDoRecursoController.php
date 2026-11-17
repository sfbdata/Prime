<?php

declare(strict_types=1);

namespace App\Conector\Controller;

use App\Conector\Mcp\ServidorMcpDoConector;
use App\Conector\Service\ServidorDeAutorizacao;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * O metadado do recurso `/mcp` (RFC 9728) nas duas URLs da 3.3 (`docs/specs/mcp-remoto-oauth.md`): a derivada do
 * recurso, que o `WWW-Authenticate` do 401 anuncia, e a da raiz. O mesmo JSON nas duas, sem sessão (`PUBLIC_ACCESS`).
 *
 * Sai do mesmo objeto que o `AuthorizationMiddleware` usa ({@see ServidorMcpDoConector::metadadoDoRecurso()}): o
 * `resource`, o `issuer` em `authorization_servers` e os escopos do recurso, sem `offline_access` (3.4 e 5.1).
 */
final class MetadadoDoRecursoController extends AbstractController
{
    #[Route(ServidorDeAutorizacao::CAMINHO_METADADO_DO_RECURSO, name: ServidorDeAutorizacao::ROTA_METADADO_DO_RECURSO, methods: ['GET'])]
    #[Route(ServidorDeAutorizacao::CAMINHO_METADADO_DO_RECURSO_RAIZ, name: ServidorDeAutorizacao::ROTA_METADADO_DO_RECURSO_RAIZ, methods: ['GET'])]
    public function metadado(): JsonResponse
    {
        return new JsonResponse(ServidorMcpDoConector::metadadoDoRecurso()->jsonSerialize(), 200, [], false);
    }
}
