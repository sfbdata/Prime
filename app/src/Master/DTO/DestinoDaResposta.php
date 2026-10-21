<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * Para onde vai a resposta de uma mensagem da Central (spec master-central-de-notificacoes.md, D5 e D6): por e-mail,
 * quando há um endereço válido; senão, aviso na Central do sistema para `usuario`, no escritório `escritorio`.
 */
final class DestinoDaResposta
{
    /** Aparado e em minúsculas, como a {@see \App\Comercial\Envio\MensagemDeEmail::normalizar()}. */
    public readonly ?string $email;

    public function __construct(
        ?string $email,
        public readonly ?User $usuario = null,
        public readonly ?Tenant $escritorio = null,
    ) {
        $this->email = $email === null ? null : mb_strtolower(trim($email));
    }

    public function porEmail(): bool
    {
        return $this->email !== null && filter_var($this->email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public function pelaCentral(): bool
    {
        return $this->usuario !== null && $this->escritorio !== null;
    }
}
