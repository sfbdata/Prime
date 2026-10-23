<?php

declare(strict_types=1);

namespace App\Tarefa\Exception;

/**
 * A meta tem despacho registrado (registro oficial, com código) e quem tem o poder sem prazo (Master, primário com a
 * opção) pediu a exclusão sem a confirmação explícita da janela. O controller responde 422.
 */
final class MetaComDespachoSemConfirmacaoException extends \DomainException
{
}
