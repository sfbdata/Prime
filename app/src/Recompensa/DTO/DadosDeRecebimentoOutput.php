<?php

declare(strict_types=1);

namespace App\Recompensa\DTO;

use App\Recompensa\Entity\DadosDeRecebimentoDeRecompensa;

/** Para quem pagar, como a lista mostra: o documento só mascarado (o completo vem do GET do diálogo de edição). */
final readonly class DadosDeRecebimentoOutput
{
    public function __construct(
        public string $nome,
        public string $documentoMascarado,
        public ?string $chavePix,
    ) {
    }

    public static function da(DadosDeRecebimentoDeRecompensa $dados): self
    {
        return new self($dados->getNome(), $dados->documentoMascarado(), $dados->getChavePix());
    }

    /** @return array<string, mixed> */
    public function paraArray(): array
    {
        return ['nome' => $this->nome, 'documentoMascarado' => $this->documentoMascarado, 'chavePix' => $this->chavePix];
    }
}
