<?php

declare(strict_types=1);

namespace App\Djen\UseCase;

use App\Djen\Entity\OabMonitorada;
use App\Djen\Repository\OabMonitoradaRepository;

/**
 * Troca só o apelido de uma OAB monitorada. Número e UF não se editam: as publicações estão ligadas à inscrição,
 * então trocar a inscrição é excluir e adicionar outra. Apelido vazio limpa o campo. A busca tenant-safe e o 404
 * ficam no controller.
 */
final class EditarApelidoOabMonitoradaUseCase
{
    public const TAMANHO_MAXIMO = 255;

    public function __construct(
        private readonly OabMonitoradaRepository $oabRepository,
    ) {
    }

    /** @throws \InvalidArgumentException apelido acima de 255 caracteres */
    public function executar(OabMonitorada $oab, ?string $apelido): void
    {
        $apelido = trim((string) $apelido);
        if (mb_strlen($apelido) > self::TAMANHO_MAXIMO) {
            throw new \InvalidArgumentException(sprintf('O apelido pode ter no máximo %d caracteres.', self::TAMANHO_MAXIMO));
        }
        $oab->setApelido($apelido === '' ? null : $apelido);
        $this->oabRepository->salvar($oab, true);
    }
}
