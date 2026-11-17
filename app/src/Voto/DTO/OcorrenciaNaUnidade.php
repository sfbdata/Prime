<?php

declare(strict_types=1);

namespace App\Voto\DTO;

use App\Voto\Enum\AvaliacaoDaOcorrencia;

/** A última ocorrência de uma unidade na tela "Unidades e links" (desenho 18, H462): texto, quem e quando, e a avaliação. */
final readonly class OcorrenciaNaUnidade
{
    public function __construct(
        public string $texto,
        public \DateTimeImmutable $registradaEm,
        public string $registradaPor,
        public ?AvaliacaoDaOcorrencia $avaliacao,
        public ?\DateTimeImmutable $decididaEm,
        public ?string $decididaPor,
        public ?string $justificativa,
    ) {
    }

    public function pendente(): bool
    {
        return AvaliacaoDaOcorrencia::Pendente === $this->avaliacao;
    }
}
