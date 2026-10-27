<?php

declare(strict_types=1);

namespace App\Shared\Google;

/**
 * Confere o `id_token` que o próprio Google devolveu na troca do código (canal TLS direto com o endpoint de token,
 * OpenID Connect Core 3.1.3.7: sem conferir a assinatura, mas conferindo `aud`, `iss`, `exp`, o `nonce` quando a ida
 * mandou um, e o e-mail confirmado). Usado pela conexão do Google Agenda e pelo "Entrar com o Google".
 */
final class IdTokenDoGoogle
{
    private const EMISSORES = ['https://accounts.google.com', 'accounts.google.com'];

    /**
     * @return array<string, mixed> a carga, com `sub` e `email` (confirmado) presentes
     *
     * @throws IdTokenDoGoogleInvalidoException
     */
    public function conferir(string $idToken, string $clienteId, ?string $nonce = null): array
    {
        $carga = $this->carga($idToken);
        $audiencia = $carga['aud'] ?? null;
        $paraNos = '' !== $clienteId && (is_array($audiencia) ? in_array($clienteId, $audiencia, true) : $audiencia === $clienteId);
        if (!$paraNos || !in_array($carga['iss'] ?? null, self::EMISSORES, true) || !is_int($carga['exp'] ?? null) || $carga['exp'] <= time()) {
            throw new IdTokenDoGoogleInvalidoException('A identificação da conta não confere (cliente, emissor ou validade).');
        }
        if (null !== $nonce && !hash_equals($nonce, is_string($carga['nonce'] ?? null) ? $carga['nonce'] : '')) {
            throw new IdTokenDoGoogleInvalidoException('A identificação da conta não confere com este pedido.');
        }
        $email = is_string($carga['email'] ?? null) ? trim($carga['email']) : '';
        if ('' === $email || true !== ($carga['email_verified'] ?? null)) {
            throw new IdTokenDoGoogleInvalidoException('O Google não informou um e-mail confirmado da conta.');
        }
        if (!is_string($carga['sub'] ?? null) || '' === $carga['sub']) {
            throw new IdTokenDoGoogleInvalidoException('O Google não informou a identificação da conta.');
        }

        return ['email' => $email] + $carga;
    }

    /** @return array<string, mixed> */
    private function carga(string $idToken): array
    {
        $partes = explode('.', $idToken);
        $carga = 3 === count($partes) ? json_decode((string) base64_decode(strtr($partes[1], '-_', '+/'), false), true) : null;
        if (!is_array($carga)) {
            throw new IdTokenDoGoogleInvalidoException('O Google não devolveu a identificação da conta.');
        }

        return $carga;
    }
}
