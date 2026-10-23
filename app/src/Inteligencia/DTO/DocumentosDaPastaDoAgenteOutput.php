<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

/** Resultado de `listar_documentos`. */
final class DocumentosDaPastaDoAgenteOutput
{
    /** @param list<DocumentoDoAgenteOutput> $documentos */
    public function __construct(
        public readonly int $pastaId,
        public readonly ?string $nup,
        public readonly array $documentos,
    ) {
    }
}
