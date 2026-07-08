<?php

declare(strict_types=1);

namespace App\Cobranca\Enum;

/**
 * Modo de operação da Carteira de Cobrança quanto à quantidade de casos ativos por objeto (SPEC §6).
 */
enum ModoCarteira: string
{
    /** Modo A — uma única cobrança ativa por objeto; novas obrigações entram no caso ativo. */
    case Unico = 'unico';

    /** Modo B — várias cobranças ativas por objeto ao mesmo tempo, cada uma com saldo próprio. */
    case Multiplo = 'multiplo';

    public function label(): string
    {
        return match ($this) {
            self::Unico => 'Uma cobrança ativa por objeto',
            self::Multiplo => 'Várias cobranças ativas por objeto',
        };
    }
}
