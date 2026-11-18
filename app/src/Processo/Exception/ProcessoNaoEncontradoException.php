<?php

declare(strict_types=1);

namespace App\Processo\Exception;

/**
 * O processo não existe para quem pergunta: id inexistente, de outro escritório, oculto pelo cadeado das pastas ou sem
 * a permissão de ver. As quatro causas dão a mesma resposta, para a recusa não dizer qual delas valeu.
 */
final class ProcessoNaoEncontradoException extends \DomainException
{
}
