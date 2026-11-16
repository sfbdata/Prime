<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/** A tela de edição de uma unidade: a assembleia e os campos atuais. */
final readonly class UnidadeParaEdicao
{
    public function __construct(
        public AssembleiaNoPainel $assembleia,
        public int $id,
        public DadosDaUnidadeInput $dados,
    ) {
    }
}
