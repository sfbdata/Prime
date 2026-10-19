<?php

declare(strict_types=1);

namespace App\Auth\DTO;

/**
 * O lembrete único de "e-mail não confirmado": quem envia é a varredura do Comercial (5d), com o texto dela.
 * `url` é absoluta e leva o token em claro, que não fica guardado em lugar nenhum.
 */
final readonly class ReemissaoDeConfirmacaoOutput
{
    public function __construct(
        public string $email,
        public string $nome,
        public string $url,
    ) {
    }
}
