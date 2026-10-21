<?php

declare(strict_types=1);

namespace App\Comercial\Message;

/** Enviar o e-mail registrado em `comercial_envio` (spec §3.1). Só o id: o handler reivindica e relê. */
final class EnviarEmail
{
    public function __construct(
        public readonly int $envioId,
    ) {
    }
}
