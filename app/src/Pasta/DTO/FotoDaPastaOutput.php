<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Pasta\Entity\Pasta;

/** A foto da pasta depois da troca ou da remoção: o nome (null = sem foto) e quando mudou, para a URL `?v=`. */
final class FotoDaPastaOutput
{
    public function __construct(
        public readonly int $pastaId,
        public readonly ?string $nome,
        public readonly ?\DateTimeImmutable $atualizadaEm,
    ) {
    }

    public static function de(Pasta $pasta): self
    {
        return new self((int) $pasta->getId(), $pasta->getFoto(), $pasta->getFotoAtualizadaEm());
    }
}
