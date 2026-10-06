<?php

declare(strict_types=1);

namespace App\Inteligencia\Exception;

use App\Inteligencia\Enum\Disponibilidade;

/**
 * A BlueJus IA não está ao alcance deste usuário/escritório agora. Carrega o motivo classificado
 * para o controller escolher status HTTP (409 ou 429) e mensagem — sem texto inventado.
 */
final class InteligenciaIndisponivelException extends \RuntimeException
{
    public function __construct(public readonly Disponibilidade $motivo)
    {
        parent::__construct($motivo->mensagem());
    }
}
