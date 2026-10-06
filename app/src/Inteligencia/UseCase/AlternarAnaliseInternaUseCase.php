<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\AnaliseOutput;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Exception\AnaliseNaoEncontradaException;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;

/**
 * O cadeado "interna do escritório" do cartão (alterna). Hoje não há link público; o campo já
 * nasce para a frente "Push Compartilhado" respeitá-lo.
 *
 * @throws AnaliseNaoEncontradaException
 */
final class AlternarAnaliseInternaUseCase
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

        $analise->alternarInterna();
        $this->analises->salvar($analise, true);

        return AnaliseOutput::fromEntity($analise);
    }
}
