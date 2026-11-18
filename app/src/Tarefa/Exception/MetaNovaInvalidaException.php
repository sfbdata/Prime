<?php

declare(strict_types=1);

namespace App\Tarefa\Exception;

/**
 * A meta nova da pasta não pode ser criada como pedida (campo obrigatório vazio, prazo fora do formato, nenhum
 * responsável do escritório, etapa que não existe, pasta excluída). A mensagem vai à pessoa como está.
 */
final class MetaNovaInvalidaException extends \DomainException
{
}
