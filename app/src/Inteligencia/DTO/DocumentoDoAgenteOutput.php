<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

use App\Pasta\Entity\PastaDocumento;

/**
 * Um item de `listar_documentos`: só metadados. O caminho no storage não sai (é detalhe de
 * infraestrutura e chave de leitura); o conteúdo também não — ler o texto é `ler_documento`, fora da
 * Fase 1 (D8).
 */
final class DocumentoDoAgenteOutput
{
    private function __construct(
        public readonly int $id,
        public readonly string $titulo,
        public readonly string $nome,
        public readonly string $categoria,
        public readonly string $tipo,
        public readonly int $tamanho,
        public readonly string $data,
        public readonly ?string $sha256,
        public readonly ?string $secao,
    ) {
    }

    public static function fromEntity(PastaDocumento $documento): self
    {
        return new self(
            id: (int) $documento->getId(),
            titulo: $documento->getTitulo(),
            nome: $documento->getNomeOriginal(),
            categoria: $documento->getCategoria(),
            tipo: $documento->getMimeType(),
            tamanho: $documento->getTamanhoBytes(),
            data: $documento->getCarregadoEm()->format(\DateTimeInterface::ATOM),
            sha256: $documento->getSha256(),
            secao: $documento->getSecao()?->getNome(),
        );
    }
}
