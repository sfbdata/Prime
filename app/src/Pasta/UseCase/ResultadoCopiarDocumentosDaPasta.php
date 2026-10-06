<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Pasta\Entity\PastaDocumento;

/**
 * As cópias que `CopiarDocumentosDaPastaUseCase` criou — já persistidas e confirmadas, na ordem da
 * seleção. A rota as devolve na forma do explorador (`ExploradorDeDocumentosOutput::arquivo()`),
 * como o upload e a edição fazem.
 */
final readonly class ResultadoCopiarDocumentosDaPasta
{
    /** @param list<PastaDocumento> $copias */
    public function __construct(
        public array $copias,
    ) {
    }
}
