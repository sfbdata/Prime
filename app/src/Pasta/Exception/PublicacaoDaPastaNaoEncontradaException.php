<?php

declare(strict_types=1);

namespace App\Pasta\Exception;

/**
 * A publicação não está entre as desta pasta (outro processo, outro escritório, inexistente) ou a aba Push está
 * oculta para quem pergunta. Quem chama responde "não encontrado", sem dizer qual dos casos foi.
 */
final class PublicacaoDaPastaNaoEncontradaException extends \DomainException
{
}
