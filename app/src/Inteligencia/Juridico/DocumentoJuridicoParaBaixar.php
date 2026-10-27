<?php

declare(strict_types=1);

namespace App\Inteligencia\Juridico;

/** A versão atual de um documento jurídico, pronta para virar download: o conteúdo, o nome e o tipo. */
final readonly class DocumentoJuridicoParaBaixar
{
    public function __construct(
        public string $conteudo,
        public string $nome,
        public string $mimeType,
    ) {
    }
}
