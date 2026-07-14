<?php

declare(strict_types=1);

namespace App\Sync\Service;

/**
 * Cifra/decifra segredos em repouso (ex.: o refresh_token OAuth de cada tenant em
 * {@see \App\Sync\Entity\TenantDriveConexao}) com `sodium_crypto_secretbox`
 * (XSalsa20-Poly1305), nativo do PHP 8.2 — sem dependência externa.
 *
 * A chave de 32 bytes vem da env `SYNC_CRYPTO_KEY` (base64), FORA do banco. O nonce
 * é aleatório por operação e prefixado ao ciphertext; a saída é base64 para caber
 * numa coluna TEXT. O texto puro nunca é logado nem serializado.
 */
final class CifradorDeSegredo
{
    public function __construct(
        private readonly string $syncCryptoKey,
    ) {
    }

    /**
     * Cifra o texto puro e devolve `base64(nonce || ciphertext)`.
     */
    public function cifrar(string $textoPuro): string
    {
        $chave = $this->chave();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cifrado = sodium_crypto_secretbox($textoPuro, $nonce, $chave);
        sodium_memzero($chave);

        return base64_encode($nonce . $cifrado);
    }

    /**
     * Decifra um blob produzido por {@see cifrar()}. Lança em blob corrompido,
     * chave errada ou adulteração (Poly1305 falha).
     */
    public function decifrar(string $blob): string
    {
        $bruto = base64_decode($blob, true);
        if ($bruto === false || \strlen($bruto) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new \RuntimeException('Blob cifrado inválido.');
        }

        $nonce = substr($bruto, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cifrado = substr($bruto, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $chave = $this->chave();
        $textoPuro = sodium_crypto_secretbox_open($cifrado, $nonce, $chave);
        sodium_memzero($chave);

        if ($textoPuro === false) {
            throw new \RuntimeException('Falha ao decifrar o segredo (chave incorreta ou dado adulterado).');
        }

        return $textoPuro;
    }

    /**
     * Decodifica e valida a chave sob demanda (não no construtor), para o container
     * subir mesmo sem `SYNC_CRYPTO_KEY` configurada em dev — só quebra ao cifrar/decifrar.
     */
    private function chave(): string
    {
        if ($this->syncCryptoKey === '') {
            throw new \RuntimeException('SYNC_CRYPTO_KEY não configurada — impossível cifrar/decifrar segredos do sync.');
        }

        $chave = base64_decode($this->syncCryptoKey, true);
        if ($chave === false || \strlen($chave) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException(sprintf(
                'SYNC_CRYPTO_KEY inválida: esperado base64 de %d bytes.',
                SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            ));
        }

        return $chave;
    }
}
