<?php

declare(strict_types=1);

namespace App\Contribua\DTO;

/** O painel "Pedidos de espaço · só Master": resumo "N pendente(s) · M liberado(s)" e até 30 linhas. */
final readonly class PainelDePedidosDeEspacoView
{
    /** @param list<LinhaDoPedidoDeEspacoView> $linhas */
    public function __construct(
        public int $pendentes,
        public int $liberados,
        public array $linhas,
    ) {
    }

    public function resumo(): string
    {
        return $this->pendentes . ' pendente(s) · ' . $this->liberados . ' liberado(s)';
    }

    public function vazio(): bool
    {
        return $this->linhas === [];
    }
}
