<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

use App\Inteligencia\Enum\DocumentoJuridico;

/** Um documento jurídico com todas as versões ajustadas dele (de qualquer questão), para o bloco único do fim da tela. */
final readonly class DocumentoDaQuestaoOutput
{
    /** @param list<VersaoDeDocumentoJuridicoOutput> $versoes a mais recente primeiro */
    public function __construct(
        public DocumentoJuridico $documento,
        public array $versoes,
    ) {
    }
}
