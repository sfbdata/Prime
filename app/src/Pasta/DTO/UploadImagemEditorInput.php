<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Entity\Tenant\Tenant;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * `$tenant` é o escritório da sessão, dono da imagem: é por ele que a leitura a acha depois
 * (`PecaImagemController`). Até a E2.3 o controller mandava aqui o diretório físico já montado.
 */
final class UploadImagemEditorInput
{
    public function __construct(
        public readonly UploadedFile $arquivo,
        public readonly Tenant $tenant,
    ) {}
}
