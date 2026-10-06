<?php

declare(strict_types=1);

namespace App\Inteligencia\Enum;

/**
 * Máquina de estados da análise. `pendente` → `processando` → `concluida` | `falhou` | `indisponivel`.
 * `falhou` pode voltar a `processando` (retry do Messenger). `indisponivel` é terminal: não há
 * provedor, repetir não muda nada.
 */
enum StatusDaAnalise: string
{
    case Pendente = 'pendente';
    case Processando = 'processando';
    case Concluida = 'concluida';
    case Falhou = 'falhou';
    case Indisponivel = 'indisponivel';

    public function emAndamento(): bool
    {
        return $this === self::Pendente || $this === self::Processando;
    }

    public function terminal(): bool
    {
        return !$this->emAndamento();
    }

    public function rotulo(): string
    {
        return match ($this) {
            self::Pendente => 'Na fila',
            self::Processando => 'Analisando',
            self::Concluida => 'Concluída',
            self::Falhou => 'Falhou',
            self::Indisponivel => 'IA não configurada',
        };
    }
}
