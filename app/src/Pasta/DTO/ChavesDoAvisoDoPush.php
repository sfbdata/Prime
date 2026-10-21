<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Pasta\Entity\AvisoDoPushDaPasta;
use App\Pasta\Enum\ChaveDoAvisoDoPush;
use App\Pasta\Service\PadroesDoAvisoDoPush;

/** O estado das três chaves de uma pasta: o da linha gravada ou, sem linha, os padrões. */
final readonly class ChavesDoAvisoDoPush
{
    public function __construct(
        public bool $responsavel,
        public bool $email,
        public bool $ia,
    ) {
    }

    public static function da(?AvisoDoPushDaPasta $linha): self
    {
        $valor = static fn (ChaveDoAvisoDoPush $chave): bool => $linha?->ligada($chave) ?? PadroesDoAvisoDoPush::de($chave);

        return new self(
            $valor(ChaveDoAvisoDoPush::Responsavel),
            $valor(ChaveDoAvisoDoPush::Email),
            $valor(ChaveDoAvisoDoPush::Ia),
        );
    }

    public function ligada(ChaveDoAvisoDoPush $chave): bool
    {
        return match ($chave) {
            ChaveDoAvisoDoPush::Responsavel => $this->responsavel,
            ChaveDoAvisoDoPush::Email => $this->email,
            ChaveDoAvisoDoPush::Ia => $this->ia,
        };
    }
}
