<?php

declare(strict_types=1);

namespace App\Pasta\Exception;

/**
 * A pasta é do escritório, mas o usuário não pode EDITÁ-LA (`resources.pasta.edit`, via
 * `PermissionChecker::canAccessResource`). O controller responde 403.
 */
final class SemPermissaoParaEditarPastaException extends \DomainException
{
}
