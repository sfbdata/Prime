<?php

declare(strict_types=1);

namespace App\Conector\Service;

/**
 * Confere o PKCE na troca do código (`docs/specs/mcp-remoto-oauth.md`, 3.2 e 9.6; RFC 7636, 4.1 e 4.6). Só S256: o
 * `code_challenge` gravado no código é `BASE64URL(SHA256(code_verifier))`, sem `=`.
 *
 * O `code_verifier` tem de ter de 43 a 128 caracteres de `[A-Za-z0-9-._~]`; fora disso, ou com o desafio diferente,
 * {@see confere()} devolve falso e quem chama responde `invalid_grant`. A comparação é em tempo constante.
 */
final class VerificadorPkce
{
    private const FORMATO_DO_VERIFIER = '/^[A-Za-z0-9\-._~]{43,128}$/';

    public function confere(string $codeVerifier, string $codeChallenge): bool
    {
        if (preg_match(self::FORMATO_DO_VERIFIER, $codeVerifier) !== 1) {
            return false;
        }

        return hash_equals($codeChallenge, self::desafioDe($codeVerifier));
    }

    /** O `code_challenge` S256 de um `code_verifier`: base64url do SHA-256 binário, sem `=`. */
    public static function desafioDe(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }
}
