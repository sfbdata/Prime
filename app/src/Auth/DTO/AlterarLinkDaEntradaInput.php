<?php

declare(strict_types=1);

namespace App\Auth\DTO;

use App\Entity\Auth\User;

/**
 * O Master mexe num link do rodapé da tela de entrada (desenho 10, SÓ MASTER).
 * `chave`: termos, priv, linkedin ou instagram. `acao`: salvar, restaurar, ocultar ou mostrar. `url` só no salvar.
 */
final class AlterarLinkDaEntradaInput
{
    public function __construct(
        public readonly string $chave,
        public readonly string $acao,
        public readonly ?string $url,
        public readonly User $alteradoPor,
    ) {
    }
}
