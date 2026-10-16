<?php

declare(strict_types=1);

namespace App\Central\DTO;

/**
 * O que o polling recebe: as mensagens novas acima do cursor, o próximo cursor e o total de não lidas.
 * `completo = false` quando veio o teto de mensagens — a tela pede de novo com o cursor novo.
 */
final class NovidadesOutput
{
    /**
     * @param list<MensagemOutput> $mensagens
     * @param list<int>            $conversasComMencao conversas com menção não lida (lançador flutuante: "@n" e filtro)
     */
    public function __construct(
        public readonly array $mensagens,
        public readonly int $cursor,
        public readonly int $naoLidas,
        public readonly bool $completo,
        public readonly array $conversasComMencao = [],
        /** F1: avisos do sino na Caixa — não lidos, urgentes não lidos e o maior id (a tela recarrega a lista quando muda). */
        public readonly int $avisosNaoLidos = 0,
        public readonly int $avisosUrgentes = 0,
        public readonly int $ultimoAviso = 0,
    ) {
    }
}
