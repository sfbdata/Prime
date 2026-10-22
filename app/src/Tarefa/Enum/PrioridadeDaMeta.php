<?php

declare(strict_types=1);

namespace App\Tarefa\Enum;

use App\Pasta\Entity\PrioridadePasta;

/**
 * Prioridade PRÓPRIA da meta (desenho 04, P:77 e P:137-144): Normal, Alta, Urgente.
 *
 * Não reaproveita `PrioridadePasta` (normal/prioridade/urgente): a meta tem vocabulário próprio e pode
 * divergir da pasta. Por decisão do dono (21/10), enquanto a análise da I.A. não define, a meta nasce
 * com a prioridade herdada da pasta (`daPasta`) e não há campo de escolha na tela.
 */
enum PrioridadeDaMeta: string
{
    case Normal  = 'normal';
    case Alta    = 'alta';
    case Urgente = 'urgente';

    /** Chave do catálogo (`metas.prioridade.*`); quem exibe aplica `|trans`. */
    public function rotulo(): string
    {
        return 'metas.prioridade.' . $this->value;
    }

    /** Herança da pasta: `urgente` vira Urgente, `prioridade` vira Alta, o resto Normal. */
    public static function daPasta(PrioridadePasta $prioridadeDaPasta): self
    {
        return match ($prioridadeDaPasta) {
            PrioridadePasta::Urgente    => self::Urgente,
            PrioridadePasta::Prioridade => self::Alta,
            PrioridadePasta::Normal     => self::Normal,
        };
    }
}
