<?php

declare(strict_types=1);

namespace App\Tarefa\Exception;

/**
 * Lançada quando o alerta "verificar a meta" não pode ser enviado: meta concluída,
 * destinatário que não é responsável dela, ou alerta repetido dentro do intervalo
 * mínimo. A mensagem é a que o usuário lê.
 */
final class AlertaDeMetaRecusadoException extends \DomainException
{
}
