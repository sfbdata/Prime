<?php

declare(strict_types=1);

namespace App\Central\DTO;

/** O despacho entregue: a conversa onde a mensagem ficou (a tela a abre) e o nome do destino ("Despachado para NOME."). */
final readonly class EncaminhamentoOutput
{
    public function __construct(
        public int $conversaId,
        public string $destinoNome,
    ) {
    }

    /** @return array{conversaId: int, destinoNome: string} */
    public function toArray(): array
    {
        return ['conversaId' => $this->conversaId, 'destinoNome' => $this->destinoNome];
    }
}
