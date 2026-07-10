<?php

declare(strict_types=1);

namespace App\Cobranca\Enum;

/**
 * Estado da Revisão de Pessoa Cobrada (SPEC §8). Uma mudança relevante de vínculo pode gerar uma
 * pendência de revisão; enquanto `pendente` ela alimenta o alerta de revisão. Depois de `resolvida`
 * (manter ou substituir a pessoa cobrada), o mesmo evento NÃO deve continuar gerando alerta (§8).
 */
enum StatusRevisao: string
{
    case Pendente = 'pendente';
    case Resolvida = 'resolvida';

    public function label(): string
    {
        return match ($this) {
            self::Pendente => 'Pendente',
            self::Resolvida => 'Resolvida',
        };
    }

    /** Classe Bootstrap `text-bg-*` para o badge de estado (apresentação, Etapa 8). */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Pendente => 'text-bg-danger',
            self::Resolvida => 'text-bg-success',
        };
    }

    /** Revisão ainda em aberto: gera alerta até ser resolvida. */
    public function estaPendente(): bool
    {
        return $this === self::Pendente;
    }
}
