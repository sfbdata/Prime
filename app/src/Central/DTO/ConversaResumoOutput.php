<?php

declare(strict_types=1);

namespace App\Central\DTO;

/**
 * Uma linha da lista de conversas: com quem, a última mensagem e quantas não lidas. `marcadaNaoLida` é o
 * "marcar como não lida" da A2 — sinal à parte, não soma mensagem. B: em grupo `outro` é nulo e valem `nome` e
 * `participantes`; `fixada`/`silenciada`/`arquivada` são de quem pediu. F3: a conversa com o BlueJus IA vem com
 * `tipo = 'ia'`, `ia = true`, `outro = null` e `nome = 'BlueJus IA'` (fixar no topo é decisão da tela).
 */
final class ConversaResumoOutput
{
    public function __construct(
        public readonly int $id,
        public readonly ?ContatoOutput $outro,
        public readonly ?MensagemOutput $ultima,
        public readonly int $naoLidas,
        public readonly bool $outroAtivo,
        public readonly bool $marcadaNaoLida,
        public readonly string $tipo,
        public readonly ?string $nome,
        public readonly int $participantes,
        public readonly bool $souAdministrador,
        public readonly bool $fixada,
        public readonly bool $silenciada,
        public readonly bool $arquivada,
        public readonly bool $ia = false,
    ) {
    }
}
