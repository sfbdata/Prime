<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/**
 * O gestor inclui na folha a batida que não existe (E10, plano-e10-ponto §6): pela ficha do colaborador, com o motivo
 * obrigatório. A batida entra como ajuste (`ponto_ajuste`, tipo inclusão); nada é gravado em `registro_ponto`.
 */
final class IncluirBatidaInput
{
    public function __construct(
        public readonly Tenant $escritorio,
        public readonly User $pessoa,
        public readonly string $tipo,
        public readonly \DateTimeImmutable $dataHora,
        public readonly string $motivo,
        public readonly User $autor,
    ) {
    }
}
