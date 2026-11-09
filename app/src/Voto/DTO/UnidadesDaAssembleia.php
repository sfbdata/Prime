<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/** A tela "Unidades e links": a assembleia, a lista e o "faltam N" do envio por e-mail. */
final readonly class UnidadesDaAssembleia
{
    /** @param list<UnidadeNaLista> $unidades */
    public function __construct(
        public AssembleiaNoPainel $assembleia,
        public array $unidades,
        public int $faltamEnvio,
    ) {
    }
}
