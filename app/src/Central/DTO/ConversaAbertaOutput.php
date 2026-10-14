<?php

declare(strict_types=1);

namespace App\Central\DTO;

/** A conversa que acabou de ser aberta: o que a tela precisa para mostrá-la (as mensagens vêm à parte). */
final class ConversaAbertaOutput
{
    public function __construct(
        public readonly int $id,
        public readonly ContatoOutput $outro,
        public readonly bool $outroAtivo,
    ) {
    }
}
