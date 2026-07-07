<?php

declare(strict_types=1);

namespace App\Djen\DTO;

use App\Djen\Entity\OabMonitorada;

/**
 * DTO de saída de uma OAB monitorada para a tela de gestão.
 */
final class OabMonitoradaOutput
{
    private function __construct(
        public readonly int $id,
        public readonly string $numero,
        public readonly string $uf,
        public readonly ?string $apelido,
        public readonly string $rotulo,
        public readonly bool $ativo,
        public readonly ?string $criadoEm,
    ) {
    }

    public static function fromEntity(OabMonitorada $oab): self
    {
        return new self(
            (int) $oab->getId(),
            $oab->getNumero(),
            $oab->getUf(),
            $oab->getApelido(),
            $oab->getRotulo(),
            $oab->isAtivo(),
            $oab->getCriadoEm()->format('d/m/Y'),
        );
    }
}
