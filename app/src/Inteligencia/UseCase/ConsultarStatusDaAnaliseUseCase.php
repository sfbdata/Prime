<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\AnaliseOutput;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Exception\AnaliseNaoEncontradaException;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;

/**
 * O polling da aba Push: status de UMA análise. A busca exige tenant E pasta (guarda de IDOR na
 * consulta); análise excluída responde como inexistente.
 *
 * @throws AnaliseNaoEncontradaException
 */
final class ConsultarStatusDaAnaliseUseCase
{
    public function __construct(
        private readonly AnaliseDeInteligenciaRepository $analises,
    ) {
    }

    public function executar(int $analiseId, int $pastaId, Tenant $tenant): AnaliseOutput
    {
        $analise = $this->analises->findOneDoTenantEAlvo($analiseId, $tenant, AnaliseDeInteligencia::ALVO_PASTA, $pastaId);
        if ($analise === null || $analise->estaExcluida()) {
            throw new AnaliseNaoEncontradaException($analiseId);
        }

        return AnaliseOutput::fromEntity($analise);
    }
}
