<?php

declare(strict_types=1);

namespace App\Master\UseCase;

use App\Master\DTO\ResolverIncidenteInput;
use App\Master\Entity\IncidenteDeDesempenho;
use App\Master\Exception\DesempenhoRecusado;
use App\Master\Repository\IncidenteDeDesempenhoRepository;
use App\Shared\Audit\EscopoDeAuditoriaDePlataforma;
use Psr\Clock\ClockInterface;

/**
 * Master marca um incidente como resolvido, com a descrição de como foi (spec master-desempenho D3).
 * Recusa o que não existe e o que já está resolvido (a data da resolução não muda por engano).
 */
final class ResolverIncidenteUseCase
{
    public function __construct(
        private readonly IncidenteDeDesempenhoRepository $incidentes,
        private readonly ClockInterface $clock,
        private readonly EscopoDeAuditoriaDePlataforma $escopoDePlataforma,
    ) {
    }

    public function executar(ResolverIncidenteInput $input): IncidenteDeDesempenho
    {
        $incidente = $this->incidentes->find($input->id);
        if (!$incidente instanceof IncidenteDeDesempenho) {
            throw new DesempenhoRecusado('Incidente não encontrado.');
        }
        if (!$incidente->estaAberto()) {
            throw new DesempenhoRecusado('Este incidente já foi resolvido.');
        }

        $incidente->resolver($input->solucao, \DateTimeImmutable::createFromInterface($this->clock->now()));
        $this->escopoDePlataforma->executar(fn () => $this->incidentes->salvar($incidente, flush: true));

        return $incidente;
    }
}
