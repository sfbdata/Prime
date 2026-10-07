<?php

declare(strict_types=1);

namespace App\Tarefa\Exception;

/**
 * Lançada quando o "Desfazer" de um "Reabrir meta" não pode devolver a conclusão original:
 * não há data lembrada para esta meta (outra sessão, janela vencida, já desfeito) ou a data
 * lembrada é impossível (antes da criação da meta ou no futuro). Nesses casos a meta NÃO é
 * concluída com a data de agora — esse era o defeito (PMC-061). A mensagem é a que o usuário lê.
 */
final class DesfazerReaberturaRecusadoException extends \DomainException
{
}
