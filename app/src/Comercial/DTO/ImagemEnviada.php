<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

/** O que a tela recebe depois do envio da imagem: o token (que a mensagem guarda) e o endereço público absoluto. */
final class ImagemEnviada
{
    public function __construct(
        public readonly string $token,
        public readonly string $url,
    ) {
    }

    /** @return array{token: string, url: string} */
    public function paraArray(): array
    {
        return ['token' => $this->token, 'url' => $this->url];
    }
}
