<?php

declare(strict_types=1);

namespace App\Cobranca\Enum;

/**
 * Modo de operação da Carteira de Cobrança quanto à quantidade de casos NÃO ENCERRADOS por objeto
 * (SPEC §6). Ver `docs/specs/cobranca-importe-enxerga-caso-judicializado.md`.
 */
enum ModoCarteira: string
{
    /** Modo A — uma única cobrança viva por objeto; novas obrigações entram no caso não encerrado. */
    case Unico = 'unico';

    /** Modo B — várias cobranças vivas por objeto ao mesmo tempo, cada uma com saldo próprio. */
    case Multiplo = 'multiplo';

    public function label(): string
    {
        return match ($this) {
            self::Unico => 'Uma cobrança viva por objeto',
            self::Multiplo => 'Várias cobranças por objeto',
        };
    }

    /** Explicação de ajuda (tooltip/popover) do que o modo significa na prática. */
    public function descricao(): string
    {
        return match ($this) {
            self::Unico => 'Cada objeto tem no máximo uma cobrança não encerrada por vez — ativa ou judicializada. Novas dívidas do mesmo objeto entram na cobrança que já está aberta.',
            self::Multiplo => 'O mesmo objeto pode ter várias cobranças abertas ao mesmo tempo, cada uma com o seu próprio saldo.',
        };
    }
}
