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

    /** Explicação de ajuda (tooltip/popover) de como cada forma cobra os honorários. */
    public function descricao(): string
    {
        return match ($this) {
            self::AcrescidoDivida => 'O percentual é somado à dívida do devedor. Em cada pagamento, o valor recebido é dividido proporcionalmente entre a dívida do credor e os honorários do escritório.',
            self::RetidoRecuperado => 'O percentual é retido do valor recuperado. A cada recuperação, os honorários saem do que foi recebido.',
            self::CobradoSeparado => 'Os honorários são cobrados à parte, direto do cliente credor — não saem do valor pago pelo devedor.',
            self::SemPercentual => 'Não há cálculo de honorários percentuais nesta carteira. O campo de percentual não se aplica.',
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
