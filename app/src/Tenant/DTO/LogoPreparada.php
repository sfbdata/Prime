<?php

declare(strict_types=1);

namespace App\Tenant\DTO;

/** A logo já conferida e regravada pelo servidor (só o que o GD desenhou, sem EXIF), no tamanho máximo da tela. */
final readonly class LogoPreparada
{
    public function __construct(
        public string $bytes,
        public string $mime,
        public string $extensao,
        public int $largura,
        public int $altura,
    ) {
    }
}
