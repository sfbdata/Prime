<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

use App\Shared\Armazenamento\ChaveDeArquivo;

/** O que o download de uma versão ajustada precisa: a chave no armazenamento e o nome original. */
final readonly class ArquivoDaVersaoJuridicaOutput
{
    public function __construct(
        public ChaveDeArquivo $chave,
        public string $nomeOriginal,
    ) {
    }
}
