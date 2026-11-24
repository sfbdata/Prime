<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Entity\Auth\User;

/**
 * Ligar ou desligar um convênio (POST /master/convenios/{id}/ligar) ou desligar todos os de uma instituição (POST
 * /master/convenios/instituicao/{chave}/desligar), JSON `{ligado, encerraEm, motivo}` (spec docs/specs/convenios.md,
 * §6.2 e §7.4). Desligar exige `encerraEm` (AAAA-MM-DD); o motivo é opcional.
 */
final class DesligarConvenioInput
{
    public function __construct(
        public readonly ?User $ator,
        public readonly bool $ligado,
        public readonly ?string $encerraEm,
        public readonly ?string $motivo,
    ) {
    }

    /** @param array<mixed> $corpo */
    public static function doJson(?User $ator, array $corpo, bool $sempreDesligar = false): self
    {
        $encerraEm = is_string($corpo['encerraEm'] ?? null) ? trim($corpo['encerraEm']) : '';
        $motivo = is_string($corpo['motivo'] ?? null) ? trim($corpo['motivo']) : '';

        return new self(
            $ator,
            !$sempreDesligar && ($corpo['ligado'] ?? null) === true,
            $encerraEm === '' ? null : $encerraEm,
            $motivo === '' ? null : $motivo,
        );
    }
}
