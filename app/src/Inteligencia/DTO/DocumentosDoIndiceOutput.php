<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

/**
 * A lista da aba Documentos do drawer (spec inteligencia-drawer-da-pasta, F4): os documentos que
 * quem vê pode marcar para o "Índice inteligente", na ordem e com o corte do contexto da IA.
 */
final readonly class DocumentosDoIndiceOutput
{
    /**
     * @param list<DocumentoDoIndiceOutput> $documentos
     */
    public function __construct(
        public array $documentos,
        /** O aceite do escritório cobre o "Ler N documentos" (PR F)? */
        public bool $leituraPermitida = true,
    ) {
    }

    public function vazia(): bool
    {
        return $this->documentos === [];
    }

    public function total(): int
    {
        return \count($this->documentos);
    }
}
