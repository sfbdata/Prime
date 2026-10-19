<?php

declare(strict_types=1);

namespace App\Contribua\Banco;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Guarda o certificado e a chave privada do banco cifrados (D6 do dono: sodium, chave vinda do ambiente).
 * No banco de dados fica só o texto cifrado; a chave `CONTRIBUA_CRYPTO_KEY` mora no ambiente, separada da
 * do Drive para trocar uma sem a outra. O conteúdo nunca volta à tela.
 */
final class CofreDeCertificado
{
    public function __construct(
        #[Autowire('%env(string:default::CONTRIBUA_CRYPTO_KEY)%')]
        private readonly string $chaveEmBase64 = '',
    ) {
    }

    public function cifrar(string $textoPuro): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($textoPuro, $nonce, $this->chave()));
    }

    public function decifrar(string $cifrado): string
    {
        $bruto = base64_decode($cifrado, true);
        if (false === $bruto || strlen($bruto) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new FalhaNoBanco('Certificado guardado ilegível.');
        }
        $puro = sodium_crypto_secretbox_open(substr($bruto, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($bruto, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->chave());
        if (false === $puro) {
            throw new FalhaNoBanco('Certificado guardado ilegível (chave do servidor trocada?).');
        }

        return $puro;
    }

    private function chave(): string
    {
        $chave = base64_decode(trim($this->chaveEmBase64), true);
        if (false === $chave || \SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen($chave)) {
            throw new FalhaNoBanco('O servidor ainda não tem a chave de cifra do banco (CONTRIBUA_CRYPTO_KEY).');
        }

        return $chave;
    }
}
