<?php

declare(strict_types=1);

namespace App\Contribua\DTO;

/** Uma linha de "Aguardando Pix" do painel do Master (o extrato confere o txid). */
final readonly class LinhaAguardandoDaCampanhaView
{
    public function __construct(
        public int $id,
        public string $nome,
        public string $oab,
        public string $cpfFinal,
        public string $valor,
        public string $txid,
        public string $em,
    ) {
    }
}
