<?php

declare(strict_types=1);

namespace App\ServiceDesk\DTO;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\ServiceDesk\Enum\PrioridadeDoChamado;
use App\ServiceDesk\Enum\TipoDeChamado;

final class AbrirChamadoInput
{
    public function __construct(
        public readonly Tenant $tenant,
        public readonly ?User $solicitante,
        public readonly TipoDeChamado $tipo,
        public readonly PrioridadeDoChamado $prioridade,
        public readonly string $assunto,
        public readonly string $descricao,
        public readonly ?\DateTimeImmutable $dataAgendada = null,
        public readonly ?string $horaAgendada = null,
    ) {
    }
}
