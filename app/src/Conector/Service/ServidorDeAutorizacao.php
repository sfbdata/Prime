<?php

declare(strict_types=1);

namespace App\Conector\Service;

/**
 * Os valores fixos do servidor de autorização próprio do conector de I.A. (`docs/specs/mcp-remoto-oauth.md`, 3.2, 3.3,
 * 3.4 e 5.1). Fonte única: o metadado RFC 8414, a resposta de autorização (`iss`), a conferência do `resource` e os
 * nomes de rota leem daqui, para não haver dois textos que precisam ser iguais.
 *
 * - {@see ISSUER}: texto exato, sem barra no fim; é o `issuer` do metadado, o item de `authorization_servers` do
 *   metadado do recurso e o `iss` de toda resposta de autorização, de sucesso e de erro (RFC 9207).
 * - {@see RESOURCE}: o único `resource` (RFC 8707) aceito no `/oauth/authorize` e no `/oauth/token`; é a URL que a
 *   pessoa cola no Claude e no ChatGPT.
 * - Escopos: `mcp:leitura` e `mcp:escrita` (5.1) e `offline_access`, que só aparece no metadado do servidor de
 *   autorização (o Claude o acrescenta quando ele está lá), nunca no do recurso nem no `WWW-Authenticate`.
 * - Rotas e caminhos do `/mcp` e do metadado do recurso (3.3 e 8): os listeners de `kernel.request`, o firewall e os
 *   controllers do PR 5 leem daqui.
 */
final class ServidorDeAutorizacao
{
    public const ISSUER = 'https://bluejus.com.br';
    public const RESOURCE = 'https://bluejus.com.br/mcp';

    public const ESCOPO_LEITURA = 'mcp:leitura';
    public const ESCOPO_ESCRITA = 'mcp:escrita';
    public const OFFLINE_ACCESS = 'offline_access';

    /** Os escopos que o servidor conhece, na ordem do `scopes_supported` do metadado RFC 8414 (3.4). */
    public const ESCOPOS_SUPORTADOS = [self::ESCOPO_LEITURA, self::ESCOPO_ESCRITA, self::OFFLINE_ACCESS];

    /** Os escopos do recurso `/mcp`: o `scopes_supported` do metadado RFC 9728, sem `offline_access` (3.4). */
    public const ESCOPOS_DO_RECURSO = [self::ESCOPO_LEITURA, self::ESCOPO_ESCRITA];

    public const ROTA_AUTORIZAR = 'conector_oauth_autorizar';
    public const ROTA_TOKEN = 'conector_oauth_token';
    public const ROTA_METADADO = 'conector_oauth_metadado';

    /**
     * O servidor de recurso (`/mcp`, PR 5). A rota do `/mcp` tem nome próprio para a auditoria (6.2, regra 13): o
     * `audit_log` grava o nome da rota, e toda alteração feita pelo conector fica marcada por ele.
     */
    public const ROTA_MCP = 'conector_mcp';
    public const CAMINHO_MCP = '/mcp';

    /**
     * O metadado do recurso (RFC 9728) nas duas URLs da 3.3: a derivada do recurso, que o `WWW-Authenticate` do 401
     * anuncia, e a da raiz. Um controller nosso serve as duas com o mesmo JSON (o `ProtectedResourceMetadata` do SDK
     * serve só a primeira, 3.1).
     */
    public const ROTA_METADADO_DO_RECURSO = 'conector_metadado_do_recurso';
    public const CAMINHO_METADADO_DO_RECURSO = '/.well-known/oauth-protected-resource/mcp';
    public const ROTA_METADADO_DO_RECURSO_RAIZ = 'conector_metadado_do_recurso_raiz';
    public const CAMINHO_METADADO_DO_RECURSO_RAIZ = '/.well-known/oauth-protected-resource';

    private function __construct()
    {
    }
}
