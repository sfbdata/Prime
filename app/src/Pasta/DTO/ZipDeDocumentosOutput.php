<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Shared\Armazenamento\ArquivoGeradoParaEntrega;

/**
 * O que `MontarZipDeDocumentosUseCase` devolve: o .zip pronto (gerado, fora da área temporária,
 * à espera da entrega que o apaga depois de enviar), o nome seguro para o download e os números
 * que a auditoria e o `LEIA-ME.txt` registram.
 */
final readonly class ZipDeDocumentosOutput
{
    public function __construct(
        public ArquivoGeradoParaEntrega $arquivo,
        public string $nomeDoZip,
        public int $arquivos,
        public int $naoEncontrados,
        public int $bytes,
    ) {
    }
}
