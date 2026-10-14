<?php

declare(strict_types=1);

namespace App\Central\DTO;

/**
 * Uma página do histórico, da mais antiga para a mais nova; `temMais` = há mensagens antes desta página.
 * `mencionaveis` (A2) = quem pode ser @mencionado nesta conversa — o autocomplete da tela.
 */
final class MensagensDaConversaOutput
{
    /**
     * @param list<MensagemOutput> $mensagens
     * @param list<ContatoOutput>  $mencionaveis
     */
    public function __construct(
        public readonly int $conversaId,
        public readonly array $mensagens,
        public readonly bool $temMais,
        public readonly int $ultimaLidaId,
        public readonly array $mencionaveis,
    ) {
    }
}
