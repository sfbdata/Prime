<?php

declare(strict_types=1);

namespace App\ServiceDesk\DTO;

use App\ServiceDesk\Entity\MovimentoDeSaldo;
use App\ServiceDesk\Enum\TipoDeMovimento;

final class MovimentoDoExtratoOutput
{
    public function __construct(
        public readonly \DateTimeImmutable $em,
        public readonly TipoDeMovimento $tipo,
        public readonly bool $pendente,
        public readonly int $valorCentavos,
        public readonly string $motivo,
    ) {
    }

    public static function fromEntity(MovimentoDeSaldo $movimento): self
    {
        return new self(
            $movimento->getCriadoEm(),
            $movimento->getTipo(),
            $movimento->estaPendente(),
            $movimento->valorCreditadoCentavos(),
            $movimento->getMotivo(),
        );
    }
}
