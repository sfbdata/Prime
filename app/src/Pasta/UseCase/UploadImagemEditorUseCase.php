<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Pasta\DTO\UploadImagemEditorInput;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\DTO\UploadImagemEditorOutput;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Http\FonteDeUploadHttp;

final class UploadImagemEditorUseCase
{
    private const MIME_TYPES_PERMITIDOS = ['image/jpeg', 'image/png'];
    private const TAMANHO_MAXIMO_BYTES  = 3 * 1024 * 1024;

    public function __construct(
        private readonly ArmazenamentoDeArquivos $armazenamento,
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

        // PASTA_IMAGEM_EDITOR mora na subpasta do escritório (isolamento físico, M5); quem monta o
        // caminho é o storage, a partir do tenant da sessão — o mesmo que a leitura usa.
        $upload     = FonteDeUploadHttp::de($input->arquivo);
        $armazenado = $upload->gravarEm(
            $this->armazenamento,
            ChavesDePasta::novaImagemDoEditor($input->tenant, $upload->extensao),
        );

        return new UploadImagemEditorOutput($armazenado->chave->nome);
    }
}
