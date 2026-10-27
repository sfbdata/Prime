<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\DTO\AbrirIncidenteInput;
use App\Master\Entity\IncidenteDeDesempenho;
use App\Master\Repository\IncidenteDeDesempenhoRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Psr\Clock\ClockInterface;

/**
 * Master abre um incidente na seção Desempenho (spec docs/specs/master-desempenho.md D3). Auditável, fora do
 * escritório da sessão: o incidente é da plataforma, então a linha da trilha fica sem conta (EscopoDeAuditoriaDePlataforma).
 */
final class AbrirIncidenteUseCase
{
    public function __construct(
        private readonly IncidenteDeDesempenhoRepository $incidentes,
        private readonly ClockInterface $clock,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    public function executar(AbrirIncidenteInput $input): IncidenteDeDesempenho
    {
        $incidente = new IncidenteDeDesempenho(
            $input->titulo,
            $input->categoria->value,
            $input->gravidade,
            \DateTimeImmutable::createFromInterface($this->clock->now()),
            $input->ator,
        );
        $this->escopoDePlataforma->executar(fn () => $this->incidentes->salvar($incidente, flush: true));

        return $incidente;
    }
}
