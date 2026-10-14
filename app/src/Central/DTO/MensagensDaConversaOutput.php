<?php

declare(strict_types=1);

namespace App\Central\DTO;

/** Uma página do histórico, da mais antiga para a mais nova; `temMais` = há mensagens antes desta página. */
final class MensagensDaConversaOutput
{
    /** @param list<MensagemOutput> $mensagens */
    public function __construct(
        public readonly int $conversaId,
        public readonly array $mensagens,
        public readonly bool $temMais,
        public readonly int $ultimaLidaId,
    ) {
    }
}
