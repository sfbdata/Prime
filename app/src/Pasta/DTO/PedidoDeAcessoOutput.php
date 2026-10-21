<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Pasta\Entity\PedidoDeAcessoAPasta;

/** O pedido como a tela mostra: o estado, a hora ("Pedido enviado às HH:MM") e quantos foram avisados. */
final class PedidoDeAcessoOutput
{
    public function __construct(
        public readonly int $id,
        public readonly string $estado,
        public readonly string $nivelPedido,
        public readonly string $hora,
        public readonly int $avisados,
    ) {
    }

    public static function fromEntity(PedidoDeAcessoAPasta $pedido, int $avisados = 0): self
    {
        return new self(
            (int) $pedido->getId(),
            $pedido->getEstado()->value,
            $pedido->getNivelPedido()->value,
            $pedido->getPedidoEm()->format('H:i'),
            $avisados,
        );
    }

    /** @return array<string, mixed> */
    public function paraJson(): array
    {
        return [
            'id'       => $this->id,
            'estado'   => $this->estado,
            'nivel'    => $this->nivelPedido,
            'hora'     => $this->hora,
            'avisados' => $this->avisados,
            'mensagem' => 'Pedido enviado às ' . $this->hora . '. Aguardando o usuário primário.',
        ];
    }
}
