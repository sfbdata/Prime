<?php

declare(strict_types=1);

namespace App\Cobranca\Enum;

/**
 * Estado da Próxima Ação operacional do Caso de Cobrança (SPEC §14). Só há UMA ação `pendente`
 * (ativa) por caso por vez; ao concluí-la o gestor registra o resultado e pode definir a próxima.
 */
enum StatusProximaAcao: string
{
    case Pendente = 'pendente';
    case Concluida = 'concluida';

    public function label(): string
    {
        return match ($this) {
            self::Pendente => 'Pendente',
            self::Concluida => 'Concluída',
        };
    }

    /** Ação ainda ativa (a "próxima coisa a fazer"); conta para o limite de 1 por caso. */
    public function estaPendente(): bool
    {
        return $this === self::Pendente;
    }
}
