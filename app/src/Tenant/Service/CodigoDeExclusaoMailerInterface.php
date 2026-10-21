<?php

declare(strict_types=1);

namespace App\Tenant\Service;

use App\Entity\Auth\User;

/** Envia o código de 6 dígitos da etapa 2 da exclusão da conta (PR 5). Interface para o dublê dos testes. */
interface CodigoDeExclusaoMailerInterface
{
    /** @throws \RuntimeException quando o e-mail não sai */
    public function enviar(User $usuario, string $escritorio, string $codigo): void;
}
