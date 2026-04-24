<?php
declare(strict_types=1);
namespace App\Profile\DTO;

use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AtualizarFotoInput
{
    public function __construct(
        public readonly UploadedFile $arquivo,
    ) {
    }
}
