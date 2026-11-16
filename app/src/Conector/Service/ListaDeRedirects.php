<?php

declare(strict_types=1);

namespace App\Conector\Service;

/**
 * A lista exata de `redirect_uri` do servidor de autorização (`docs/specs/mcp-remoto-oauth.md`, 3.4 e 9.6). O
 * `redirect_uri` do pedido tem de estar no documento CIMD do cliente **e** nesta lista. Muda só por PR.
 *
 * - Claude (claude.ai, Desktop, celular): `https://claude.ai/api/mcp/auth_callback`;
 * - ChatGPT: `https://chatgpt.com/connector_platform_oauth_redirect` (com RFC 9207) ou
 *   `https://chatgpt.com/connector/oauth/{callback_id}`;
 * - Claude Code: loopback `http://127.0.0.1:<porta>/callback` e `http://localhost:<porta>/callback`, com qualquer porta
 *   (RFC 8252, 7.3: a porta é escolhida na hora pelo app). Só esses dois hosts e só o caminho `/callback` da tabela da
 *   3.4; `[::1]`, `localhost.evil.com` e outro caminho ficam de fora.
 *
 * As expressões terminam em `\z`, não em `$`: o `$` do PCRE aceita uma quebra de linha no fim.
 */
final class ListaDeRedirects
{
    public const EXATOS = [
        'https://claude.ai/api/mcp/auth_callback',
        'https://chatgpt.com/connector_platform_oauth_redirect',
    ];

    /** `https://chatgpt.com/connector/oauth/{callback_id}`. */
    private const CHATGPT_COM_CALLBACK_ID = '#^https://chatgpt\.com/connector/oauth/[A-Za-z0-9_-]+\z#';

    /** Loopback do Claude Code, com a porta opcional no grupo 1. */
    private const LOOPBACK = '#^http://(?:127\.0\.0\.1|localhost)(?::([0-9]{1,5}))?/callback\z#';

    /** Qualquer endereço de loopback (para comparar com o documento sem a porta): host no grupo 1, caminho no 3. */
    private const LOOPBACK_QUALQUER = '#^http://(127\.0\.0\.1|localhost)(?::[0-9]{1,5})?(/[^\#?]*(?:\?[^\#]*)?)?\z#';

    /** O `redirect_uri` está na lista do servidor? */
    public function permite(string $redirectUri): bool
    {
        if (\in_array($redirectUri, self::EXATOS, true) || preg_match(self::CHATGPT_COM_CALLBACK_ID, $redirectUri) === 1) {
            return true;
        }

        if (preg_match(self::LOOPBACK, $redirectUri, $partes) !== 1) {
            return false;
        }
        $porta = $partes[1] ?? '';

        return $porta === '' || ((int) $porta >= 1 && (int) $porta <= 65535);
    }

    /**
     * O `redirect_uri` está no documento do cliente? Byte a byte, menos a porta do loopback (RFC 8252, 7.3): o documento
     * do Claude Code traz `http://127.0.0.1/callback`, e o pedido chega com a porta que o app abriu.
     *
     * @param list<string> $redirectsDoDocumento
     */
    public function constaDoDocumento(array $redirectsDoDocumento, string $redirectUri): bool
    {
        if (\in_array($redirectUri, $redirectsDoDocumento, true)) {
            return true;
        }

        $semPorta = self::loopbackSemPorta($redirectUri);
        if ($semPorta === null) {
            return false;
        }
        foreach ($redirectsDoDocumento as $doDocumento) {
            if (self::loopbackSemPorta($doDocumento) === $semPorta) {
                return true;
            }
        }

        return false;
    }

    private static function loopbackSemPorta(string $uri): ?string
    {
        if (preg_match(self::LOOPBACK_QUALQUER, $uri, $partes) !== 1) {
            return null;
        }

        return 'http://' . $partes[1] . ($partes[2] ?? '');
    }
}
