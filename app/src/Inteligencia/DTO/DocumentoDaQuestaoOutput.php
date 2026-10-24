<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

use App\Inteligencia\Enum\DocumentoJuridico;

/** Um documento que a questão manda atualizar, com todas as versões ajustadas dele (de qualquer questão). */
final readonly class DocumentoDaQuestaoOutput
{
    /** @param list<VersaoDeDocumentoJuridicoOutput> $versoes a mais recente primeiro */
    public function __construct(
        public DocumentoJuridico $documento,
        public array $versoes,
    ) {
    }
}
