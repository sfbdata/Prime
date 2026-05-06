<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Pasta\DTO\UploadImagemEditorInput;
use App\Pasta\DTO\UploadImagemEditorOutput;
use App\Shared\Service\ArquivoStorageInterface;

final class UploadImagemEditorUseCase
{
    private const MIME_TYPES_PERMITIDOS = ['image/jpeg', 'image/png'];
    private const TAMANHO_MAXIMO_BYTES  = 3 * 1024 * 1024;

    public function __construct(
        private readonly ArquivoStorageInterface $storage,
    ) {}

    public function executar(UploadImagemEditorInput $input): UploadImagemEditorOutput
    {
        $mimeType = $input->arquivo->getMimeType() ?? '';

        if (!in_array($mimeType, self::MIME_TYPES_PERMITIDOS, true)) {
            throw new \InvalidArgumentException('Apenas imagens JPEG e PNG são permitidas no editor.');
        }

        if ($input->arquivo->getSize() > self::TAMANHO_MAXIMO_BYTES) {
            throw new \InvalidArgumentException('A imagem não pode ter mais de 3 MB.');
        }

        $nomeArquivo = $this->storage->salvar($input->arquivo, $input->diretorio);

        return new UploadImagemEditorOutput($nomeArquivo);
    }
}
