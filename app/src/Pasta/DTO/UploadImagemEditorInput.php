<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use Symfony\Component\HttpFoundation\File\UploadedFile;

final class UploadImagemEditorInput
{
    public function __construct(
        public readonly UploadedFile $arquivo,
        public readonly string $diretorio,
    ) {}
}
