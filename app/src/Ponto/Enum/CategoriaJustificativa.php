<?php

declare(strict_types=1);

namespace App\Ponto\Enum;

enum CategoriaJustificativa: string
{
    case LegalClt      = 'legal_clt';
    case Operacional   = 'operacional';
    case Intercorrencia = 'intercorrencia';
    case Tecnica       = 'tecnica';
    case Critica       = 'critica';
    case RegimeEspecial = 'regime_especial';

    public function label(): string
    {
        return match ($this) {
            self::LegalClt       => 'Legais (CLT / Legislação)',
            self::Operacional    => 'Operacionais',
            self::Intercorrencia => 'Intercorrências',
            self::Tecnica        => 'Técnicas do Sistema',
            self::Critica        => 'Situações Críticas',
            self::RegimeEspecial => 'Feriados e Regimes Especiais',
        };
    }
}
