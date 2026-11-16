<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/**
 * Uma linha da relação nominal do voto aberto (desenho 18): quem votou (bloco, unidade, responsável), em quê e quando,
 * em segundos. Só existe para pauta de voto aberto. A escolha é uma opção da pauta (número e rótulo; `abstencao` para
 * abstenção ou voto em branco) ou um número nulo.
 */
final readonly class VotoNominalNaPauta
{
    public function __construct(
        public string $bloco,
        public string $identificacao,
        public ?string $responsavel,
        public ?int $numero,
        public ?string $rotulo,
        public bool $abstencao,
        public ?string $numeroNulo,
        public string $peso,
        public \DateTimeImmutable $em,
    ) {
    }

    public function nulo(): bool
    {
        return null !== $this->numeroNulo;
    }
}
