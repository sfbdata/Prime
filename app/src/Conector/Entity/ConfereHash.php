<?php

declare(strict_types=1);

namespace App\Conector\Entity;

/**
 * As tabelas do conector guardam só o HASH de código, token, renovação e id de proposta, nunca o valor
 * (`docs/specs/mcp-remoto-oauth.md`, 13.2). O formato é SHA-256 (ou HMAC-SHA-256) em hexadecimal minúsculo: 64
 * caracteres. Conferir o formato na entidade pega o erro de gravar o próprio segredo no lugar do hash.
 */
trait ConfereHash
{
    private static function exigirHash(string $valor, string $campo): string
    {
        if (preg_match('/^[0-9a-f]{64}$/', $valor) !== 1) {
            throw new \InvalidArgumentException(sprintf('O campo %s precisa ser um hash SHA-256 em hexadecimal.', $campo));
        }

        return $valor;
    }
}
