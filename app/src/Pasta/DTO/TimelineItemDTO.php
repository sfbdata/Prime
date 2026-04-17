<?php

namespace App\Pasta\DTO;

final readonly class TimelineItemDTO
{
    public function __construct(
        public TimelineItemType $tipo,
        public \DateTimeImmutable $dataHora,
        public string $titulo,
        public ?string $detalhe,
        public ?string $autorNome,
        public ?string $autorEmail,
        public string $icone,
        public string $badgeCss,
        public ?string $arquivoAnexo = null,
        public ?int $mensagemId = null,
    ) {}
}
