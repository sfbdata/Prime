<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

/** A imagem da campanha já conferida e regravada pelo servidor: só os bytes que o GD desenhou, sem EXIF. */
final class ImagemPreparada
{
    public function __construct(
        public readonly string $bytes,
        public readonly string $mime,
        public readonly string $extensao,
        public readonly int $largura,
        public readonly int $altura,
    ) {
    }

    public function tamanho(): int
    {
        return \strlen($this->bytes);
    }

    public function sha256(): string
    {
        return hash('sha256', $this->bytes);
    }
}
