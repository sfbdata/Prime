<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

final class UploadImagemEditorOutput
{
    public function __construct(
        public readonly string $nomeArquivo,
    ) {}
}
