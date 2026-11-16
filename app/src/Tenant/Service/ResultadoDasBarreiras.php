<?php

declare(strict_types=1);

namespace App\Tenant\Service;

/**
 * O que as {@see BarreirasDoEscritorio} disseram sobre uma pessoa num escritório: liberada, recusada (com a mensagem
 * da primeira barreira que falhou) ou o caso próprio do primário de conta "suspensa a pedido".
 *
 * O primário de conta suspensa a pedido é um caso à parte porque cada porta o trata de um jeito (spec
 * mcp-remoto-oauth, seção 4, regra 1): o navegador o prende ao Meu Perfil, onde ele desiste do pedido; o conector de
 * I.A. não tem Meu Perfil e o recusa. Por isso {@see recusa()} devolve, nesse caso, a
 * {@see BloqueioPorPedidoDeExclusao::MENSAGEM}: quem só quer saber "entra ou não" (o conector) recusa com ela, e quem
 * tem o Meu Perfil (o listener do navegador) pergunta antes por {@see ePrimarioNaContaSuspensaAPedido()}.
 */
final class ResultadoDasBarreiras
{
    private function __construct(
        private readonly ?string $recusa,
        private readonly bool $primarioNaContaSuspensaAPedido,
    ) {
    }

    public static function liberado(): self
    {
        return new self(null, false);
    }

    public static function recusado(string $mensagem): self
    {
        return new self($mensagem, false);
    }

    public static function primarioNaContaSuspensaAPedido(): self
    {
        return new self(BloqueioPorPedidoDeExclusao::MENSAGEM, true);
    }

    public function estaLiberado(): bool
    {
        return $this->recusa === null;
    }

    public function ePrimarioNaContaSuspensaAPedido(): bool
    {
        return $this->primarioNaContaSuspensaAPedido;
    }

    /** A mensagem da recusa; nula quando liberado. No primário de conta suspensa a pedido, a do pedido de exclusão. */
    public function recusa(): ?string
    {
        return $this->recusa;
    }
}
