<?php

declare(strict_types=1);

namespace App\ServiceDesk\Enum;

/** O que o escritório pede à equipe técnica (desenho 12, "Abrir chamado"). */
enum TipoDeChamado: string
{
    case Suporte = 'suporte';
    case Agenda = 'agenda';

    public function rotulo(): string
    {
        return match ($this) {
            self::Suporte => 'Suporte técnico',
            self::Agenda => 'Treinamento',
        };
    }

    public function descricao(): string
    {
        return match ($this) {
            self::Suporte => 'Dúvidas e ajustes no sistema',
            self::Agenda => 'Treinamento com a equipe técnica em horário marcado',
        };
    }

    public function icone(): string
    {
        return match ($this) {
            self::Suporte => 'bi-tools',
            self::Agenda => 'bi-mortarboard',
        };
    }

    public function exigeAgendamento(): bool
    {
        return self::Agenda === $this;
    }
}
