<?php

declare(strict_types=1);

namespace App\Central\DTO;

/**
 * Uma linha da lista de conversas: com quem, a última mensagem e quantas não lidas. `marcadaNaoLida` é o
 * "marcar como não lida" da A2 — sinal à parte, não soma mensagem.
 */
final class ConversaResumoOutput
{
    public function __construct(
        public readonly int $id,
        public readonly ContatoOutput $outro,
        public readonly ?MensagemOutput $ultima,
        public readonly int $naoLidas,
        public readonly bool $outroAtivo,
        public readonly bool $marcadaNaoLida,
    ) {
    }
}
