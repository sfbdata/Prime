<?php

declare(strict_types=1);

namespace App\Cobranca\Enum;

/**
 * Forma de uma liquidação da dívida (SPEC §4/§11): o saldo pode ser reduzido por dinheiro, bem
 * móvel, bem imóvel ou outra forma/direito aceito na negociação. O valor reconhecido para a
 * liquidação pode diferir do valor atribuído ao bem (§11). Dinheiro recebido do devedor no fluxo
 * normal entra como Pagamento (que rateia honorários, §18); a Liquidação reduz o saldo diretamente.
 */
enum TipoLiquidacao: string
{
    case Dinheiro = 'dinheiro';
    case BemMovel = 'bem_movel';
    case BemImovel = 'bem_imovel';
    case Outro = 'outro';

    public function label(): string
    {
        return match ($this) {
            self::Dinheiro => 'Dinheiro',
            self::BemMovel => 'Bem móvel',
            self::BemImovel => 'Bem imóvel',
            self::Outro => 'Outro bem ou direito',
        };
    }
}
