<?php

declare(strict_types=1);

namespace App\Cliente\Exception;

/** Quem pediu não pode VER o cliente (permissão `cliente`, ação view). A tela responde 403. */
final class SemPermissaoParaVerClienteException extends \DomainException
{
}
