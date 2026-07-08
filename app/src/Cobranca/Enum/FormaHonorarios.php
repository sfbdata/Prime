<?php

declare(strict_types=1);

namespace App\Cobranca\Enum;

/**
 * Forma de cobrança dos honorários advocatícios definida na Carteira (SPEC §18).
 * Honorários são sempre separados da dívida pertencente ao credor (invariável 18).
 */
enum FormaHonorarios: string
{
    /** Honorários acrescidos à dívida (pagamento parcial rateado proporcionalmente). */
    case AcrescidoDivida = 'acrescido_divida';

    /** Honorários retidos do valor recuperado (realizados proporcionalmente a cada recuperação). */
    case RetidoRecuperado = 'retido_recuperado';

    /** Honorários cobrados separadamente do cliente (recebimento efetivo pertence ao Financeiro). */
    case CobradoSeparado = 'cobrado_separado';

    /** Sem cálculo percentual de honorários para o caso. */
    case SemPercentual = 'sem_percentual';

    public function label(): string
    {
        return match ($this) {
            self::AcrescidoDivida => 'Honorários acrescidos à dívida',
            self::RetidoRecuperado => 'Honorários retidos do valor recuperado',
            self::CobradoSeparado => 'Honorários cobrados separadamente do cliente',
            self::SemPercentual => 'Sem honorário percentual',
        };
    }

    /** Indica se a forma exige um percentual configurado. */
    public function exigePercentual(): bool
    {
        return match ($this) {
            self::AcrescidoDivida, self::RetidoRecuperado, self::CobradoSeparado => true,
            self::SemPercentual => false,
        };
    }
}
