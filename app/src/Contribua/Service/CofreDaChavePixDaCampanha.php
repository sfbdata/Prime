<?php

declare(strict_types=1);

namespace App\Contribua\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Guarda a chave Pix da devolução cifrada (sodium secretbox). A chave de cifra deriva do `kernel.secret`, para não
 * depender da `CONTRIBUA_CRYPTO_KEY`, que não está configurada em todo ambiente. Só o Master vê a chave decifrada.
 */
final class CofreDaChavePixDaCampanha
{
    public function __construct(
        #[Autowire('%kernel.secret%')]
        private readonly string $segredo,
    ) {
    }

    public function cifrar(string $chavePix): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($chavePix, $nonce, $this->chave()));
    }

    /** Devolve null quando o texto guardado não abre (por exemplo, o segredo do servidor mudou). */
    public function decifrar(string $cifrado): ?string
    {
        $bruto = base64_decode($cifrado, true);
        if ($bruto === false || \strlen($bruto) <= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $puro = sodium_crypto_secretbox_open(substr($bruto, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($bruto, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $this->chave());

        return $puro === false ? null : $puro;
    }

    private function chave(): string
    {
        return sodium_crypto_generichash('contribua-campanha-pix' . $this->segredo, '', \SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }
}
