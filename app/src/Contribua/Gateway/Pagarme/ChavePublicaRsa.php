<?php

declare(strict_types=1);

namespace App\Contribua\Gateway\Pagarme;

/**
 * Monta a chave pública RSA em PEM a partir do JWK (`n` e `e` em base64url, RFC 7518 §6.3.1), para o
 * `openssl_verify`. O PEM é o SubjectPublicKeyInfo em DER (RFC 5280): SEQUENCE { SEQUENCE { OID rsaEncryption,
 * NULL }, BIT STRING { SEQUENCE { INTEGER n, INTEGER e } } }.
 */
final class ChavePublicaRsa
{
    /** OID 1.2.840.113549.1.1.1 (rsaEncryption) seguido de NULL. */
    private const ALGORITMO = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";

    public static function pemDoJwk(string $n, string $e): ?string
    {
        $modulo = self::base64url($n);
        $expoente = self::base64url($e);
        if ($modulo === null || $expoente === null) {
            return null;
        }
        $rsa = self::der(0x30, self::inteiro($modulo) . self::inteiro($expoente));
        $info = self::der(0x30, self::ALGORITMO . self::der(0x03, "\x00" . $rsa));

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($info), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    public static function base64url(string $texto): ?string
    {
        $base64 = strtr($texto, '-_', '+/');
        $binario = base64_decode(str_pad($base64, (int) (ceil(\strlen($base64) / 4) * 4), '='), true);

        return $binario === false || $binario === '' ? null : $binario;
    }

    /** INTEGER sem sinal: zero à esquerda quando o primeiro bit está ligado. */
    private static function inteiro(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");

        return self::der(0x02, (\ord($bytes[0] ?? "\x00") & 0x80) !== 0 ? "\x00" . $bytes : $bytes);
    }

    private static function der(int $tipo, string $conteudo): string
    {
        $tamanho = \strlen($conteudo);
        if ($tamanho < 0x80) {
            return \chr($tipo) . \chr($tamanho) . $conteudo;
        }
        $bytes = ltrim(pack('N', $tamanho), "\x00");

        return \chr($tipo) . \chr(0x80 | \strlen($bytes)) . $bytes . $conteudo;
    }
}
