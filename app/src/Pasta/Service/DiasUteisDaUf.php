<?php

declare(strict_types=1);

namespace App\Pasta\Service;

/**
 * O calendário de dias úteis já com a UF do escritório. Passado ao {@see LeitorDeDatas::ler()} liga o modo
 * novo da estimativa (feriados do sistema e corridos que terminam em dia não útil vão ao próximo útil).
 */
final readonly class DiasUteisDaUf
{
    public function __construct(
        private DiasUteisDoEscritorio $dias,
        private ?string $uf,
    ) {
    }

    public function ehUtil(\DateTimeImmutable $dia): bool
    {
        return $this->dias->ehUtil($dia, $this->uf);
    }

    public function proximoUtil(\DateTimeImmutable $dia): \DateTimeImmutable
    {
        return $this->dias->proximoUtil($dia, $this->uf);
    }
}
