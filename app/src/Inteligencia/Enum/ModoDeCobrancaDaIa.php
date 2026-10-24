<?php

declare(strict_types=1);

namespace App\Inteligencia\Enum;

/**
 * Como a BlueJus IA é cobrada de UM escritório, escolhido pelo Master (spec ia-anthropic-ligar §10, Q4). Neste PR só
 * é gravado e mostrado; o orçamento mensal em dólares passa a valer na disponibilidade no PR C.
 */
enum ModoDeCobrancaDaIa: string
{
    case Incluso = 'incluso';
    case Adicional = 'adicional';
    case LimiteGratis = 'limite_gratis';

    public function rotulo(): string
    {
        return match ($this) {
            self::Incluso => 'Incluso no plano',
            self::Adicional => 'Adicional pago',
            self::LimiteGratis => 'Limite grátis, depois cobra',
        };
    }
}
