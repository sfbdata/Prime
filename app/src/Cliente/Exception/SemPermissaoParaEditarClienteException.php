<?php

declare(strict_types=1);

namespace App\Cliente\Exception;

/** Quem pediu não pode EDITAR o cliente (permissão `cliente`, ação edit). O controller responde 403. */
final class SemPermissaoParaEditarClienteException extends \DomainException
{
}
