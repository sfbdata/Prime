<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\DTO;

use App\Shared\Armazenamento\ChaveDeArquivo;

/** Um arquivo da tela pronto para a `EntregaDeArquivo`: a chave já validada pelo escritório e o nome do download. */
final readonly class ArquivoParaEntregar
{
    public function __construct(
        public ChaveDeArquivo $chave,
        public string $nome,
    ) {
    }
}
