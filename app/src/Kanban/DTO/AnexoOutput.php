<?php

declare(strict_types=1);

namespace App\Kanban\DTO;

use App\Kanban\Entity\KanbanAnexo;

final class AnexoOutput
{
    public function __construct(
        public readonly int $id,
        public readonly string $nomeOriginal,
        public readonly string $tamanhoFormatado,
        public readonly string $mimeType,
        public readonly bool $isImagem,
        public readonly \DateTimeImmutable $criadoEm,
        public readonly string $criadoPorNome,
    ) {
    }

    public static function fromEntity(KanbanAnexo $anexo): self
    {
        $criadoPor = $anexo->getCriadoPor();

        return new self(
            id: $anexo->getId(),
            nomeOriginal: $anexo->getNomeOriginal(),
            tamanhoFormatado: $anexo->tamanhoFormatado(),
            mimeType: $anexo->getMimeType(),
            isImagem: $anexo->isImagem(),
            criadoEm: $anexo->getCriadoEm() ?? new \DateTimeImmutable(),
            criadoPorNome: $criadoPor ? ($criadoPor->getFullName() ?? $criadoPor->getEmail()) : '',
        );
    }
}
