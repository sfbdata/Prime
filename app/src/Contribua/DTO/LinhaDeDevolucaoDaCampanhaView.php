<?php

declare(strict_types=1);

namespace App\Contribua\DTO;

/** Uma linha de "Pedidos de devolução" do painel do Master. */
final readonly class LinhaDeDevolucaoDaCampanhaView
{
    public function __construct(
        public int $id,
        public string $nome,
        public string $oab,
        public string $valor,
        public string $pix,
        public string $em,
        public ?string $motivo,
        public bool $pendente,
    ) {
    }
}
