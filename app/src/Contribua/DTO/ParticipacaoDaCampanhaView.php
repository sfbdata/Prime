<?php

declare(strict_types=1);

namespace App\Contribua\DTO;

/** A inscrição da própria pessoa, como a janela da campanha a mostra. */
final readonly class ParticipacaoDaCampanhaView
{
    public function __construct(
        /** aguardando_pagamento | ativa | devolucao_pedida | devolvida */
        public string $situacao,
        /** "dd/mm/aaaa" do pagamento (ou da inscrição, se ainda não pagou). */
        public string $desde,
        public int $diasRestantes,
        public bool $podeDevolver,
        public string $valorDevolucao,
        public ?string $pedidaEm,
        /** O "copia e cola" do Pix, só enquanto aguarda pagamento. */
        public ?string $pixCodigo,
        public string $preco,
    ) {
    }
}
