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
        public ?string $somaDasFracoes = null,
        public bool $somaForaDoEsperado = false,
        /** Hash da lista (sha256) gravado quando ela travou; null até lá (e nas travadas antes dele existir). */
        public ?string $hashDaLista = null,
        /** "N alteração(ões) por decisão do conselho": tornadas aptas depois da trava. */
        public int $alteracoesDoConselho = 0,
        /** A assembleia ainda não acabou: dá para registrar ocorrência (a pessoa pode, ver `voto_edita`). */
        public bool $aceitaOcorrencia = true,
    ) {
    }
}
