<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

use App\Entity\Tarefa\TarefaMensagem;

/**
 * O que a página de validação do registro confirma (spec `minhas-metas-esteira-2210.md`, C13): meta, autor, cargo,
 * data e natureza. O texto do despacho fica de fora de propósito.
 */
final class RegistroDoDespachoOutput
{
    public function __construct(
        public readonly string $codigo,
        public readonly int $metaId,
        public readonly int $metaNumero,
        public readonly string $metaTitulo,
        public readonly string $autor,
        public readonly ?string $cargo,
        public readonly \DateTimeImmutable $registradoEm,
        public readonly ?string $natureza,
    ) {
    }

    /** Autor e cargo são os congelados no registro, não os de hoje. */
    public static function fromEntity(TarefaMensagem $despacho): self
    {
        $meta = $despacho->getTarefa();

        return new self(
            codigo: (string) $despacho->getCodigo(),
            metaId: (int) $meta?->getId(),
            metaNumero: (int) ($meta?->getNumero() ?? $meta?->getId()),
            metaTitulo: (string) $meta?->getTitulo(),
            autor: (string) $despacho->getRegistradoPorNome(),
            cargo: $despacho->getRegistradoPorCargo(),
            registradoEm: $despacho->getCriadoEm(),
            natureza: $despacho->getNatureza()?->rotulo(),
        );
    }
}
