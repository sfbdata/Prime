<?php

declare(strict_types=1);

namespace App\Tarefa\Exception;

/**
 * A pessoa não tem o módulo Tarefas no escritório (`AcessoAoModuloTarefas::pode`). Sem ele não cria meta,
 * nem pela tela da pasta nem pelo conector.
 */
final class SemAcessoAoModuloTarefasException extends \DomainException
{
}
