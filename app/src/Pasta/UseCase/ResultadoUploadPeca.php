<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Pasta\Entity\PastaDocumento;
use App\Shared\Service\ResultadoCompressao;

final readonly class ResultadoUploadPeca
{
    public function __construct(
        public PastaDocumento $documento,
        public ResultadoCompressao $compressao,
    ) {}
}
