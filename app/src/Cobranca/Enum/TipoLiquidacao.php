<?php

declare(strict_types=1);

namespace App\Cobranca\Enum;

/**
 * Forma NÃO monetária de liquidação da dívida (SPEC §11): o saldo pode ser reduzido por bem móvel,
 * bem imóvel ou outra forma/direito aceito na negociação. Dinheiro do devedor NÃO é liquidação —
 * entra EXCLUSIVAMENTE pelo fluxo de `Pagamento` (alocação + rateio de honorários + correção
 * auditável + honorários realizados, §11/§18). O valor reconhecido para a liquidação pode diferir
 * do valor atribuído ao bem (§11).
 */
enum TipoLiquidacao: string
{
    case BemMovel = 'bem_movel';
    case BemImovel = 'bem_imovel';
    case Outro = 'outro';

    public function label(): string
    {
        return match ($this) {
            self::BemMovel => 'Bem móvel',
            self::BemImovel => 'Bem imóvel',
            self::Outro => 'Outro bem ou direito',
        };
    }
}
