<?php

declare(strict_types=1);

namespace App\Voto\DTO;

use App\Voto\Enum\FormatoDaMinuta;

/** A minuta de ata pronta para baixar: os bytes (gerados na hora, nunca gravados), o formato e o nome do arquivo. */
final readonly class MinutaDeAtaGerada
{
    public function __construct(
        public string $conteudo,
        public FormatoDaMinuta $formato,
        public string $nomeDoArquivo,
        public bool $noPapelTimbrado,
    ) {
    }
}
