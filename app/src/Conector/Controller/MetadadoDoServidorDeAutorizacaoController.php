<?php

declare(strict_types=1);

namespace App\Conector\Controller;

use App\Conector\Service\ServidorDeAutorizacao;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * O metadado do servidor de autorização (RFC 8414) em `GET /.well-known/oauth-authorization-server`
 * (`docs/specs/mcp-remoto-oauth.md`, 3.3 e 3.4, decisões de 16/11/2026). JSON fixo, sem sessão (`PUBLIC_ACCESS`).
 *
 * O que o Claude e o ChatGPT olham antes de conectar:
 * - `issuer` é o texto exato {@see ServidorDeAutorizacao::ISSUER}, sem barra no fim: igual ao `authorization_servers`
 *   do metadado do recurso e ao `iss` de toda resposta de autorização (RFC 9207);
 * - `client_id_metadata_document_supported: true` E `"none"` em `token_endpoint_auth_methods_supported`: sem os dois, o
 *   Claude cai para o DCR, que não existe aqui;
 * - `offline_access` em `scopes_supported`: o Claude o acrescenta ao pedido quando ele está aqui (5.1);
 * - sem `registration_endpoint` (DCR fora do piloto) e sem `jwks_uri` (token opaco, 3.2).
 *
 * Os endereços são o `issuer` mais o caminho fixo da 3.3: o metadado anuncia o servidor de produção, não o host de quem
 * pergunta.
 */
final class MetadadoDoServidorDeAutorizacaoController extends AbstractController
{
    public const CAMINHO_AUTORIZAR = '/oauth/authorize';
    public const CAMINHO_TOKEN = '/oauth/token';

    #[Route('/.well-known/oauth-authorization-server', name: ServidorDeAutorizacao::ROTA_METADADO, methods: ['GET'])]
    public function metadado(): JsonResponse
    {
        return new JsonResponse(self::documento());
    }

    /** @return array<string, string|bool|list<string>> */
    public static function documento(): array
    {
        return [
            'issuer' => ServidorDeAutorizacao::ISSUER,
            'authorization_endpoint' => ServidorDeAutorizacao::ISSUER . self::CAMINHO_AUTORIZAR,
            'token_endpoint' => ServidorDeAutorizacao::ISSUER . self::CAMINHO_TOKEN,
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'client_id_metadata_document_supported' => true,
            'authorization_response_iss_parameter_supported' => true,
            'scopes_supported' => ServidorDeAutorizacao::ESCOPOS_SUPORTADOS,
        ];
    }
}
