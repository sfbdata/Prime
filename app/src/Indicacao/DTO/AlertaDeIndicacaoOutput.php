<?php

declare(strict_types=1);

namespace App\Indicacao\DTO;

use App\Indicacao\Entity\IndicacaoAlerta;

/** Um alerta de "Disparo alto de convites" no card do Master (contrato com a 5d). */
final class AlertaDeIndicacaoOutput
{
    public function __construct(
        public readonly string $texto,
        public readonly \DateTimeImmutable $em,
    ) {
    }

    public static function fromEntity(IndicacaoAlerta $alerta): self
    {
        return new self($alerta->getTexto(), $alerta->getEm());
    }
}
