<?php

declare(strict_types=1);

namespace App\Dashboard\DTO;

use App\Dashboard\Entity\DashboardZeramento;

/** O zeramento gravado, para a tela confirmar ("Total metas zerado · Ana") e recarregar a tabela. */
final class ZeramentoOutput
{
    public function __construct(
        public readonly int $id,
        public readonly string $coluna,
        public readonly ?int $pessoaId,
        public readonly string $contarDesde,
    ) {
    }

    public static function fromEntity(DashboardZeramento $zeramento): self
    {
        return new self(
            id: (int) $zeramento->getId(),
            coluna: $zeramento->getColuna(),
            pessoaId: $zeramento->getUsuario()?->getId(),
            contarDesde: $zeramento->getContarDesde()->format(\DateTimeInterface::ATOM),
        );
    }

    /** @return array{id: int, coluna: string, pessoa: ?int, contarDesde: string} */
    public function paraArray(): array
    {
        return ['id' => $this->id, 'coluna' => $this->coluna, 'pessoa' => $this->pessoaId, 'contarDesde' => $this->contarDesde];
    }
}
