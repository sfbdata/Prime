<?php

declare(strict_types=1);

namespace App\Sync\DTO;

/**
 * Resultado da troca do `code` OAuth pelo refresh_token (fluxo "Conectar meu Drive").
 * O refresh_token vem em TEXTO PURO aqui — o chamador deve cifrá-lo antes de persistir
 * ({@see \App\Sync\Service\CifradorDeSegredo}) e nunca logá-lo.
 */
final readonly class ResultadoAutenticacaoDrive
{
    public function __construct(
        public string $refreshToken,
        public ?string $email,
        public ?string $escopo,
    ) {
    }
}
