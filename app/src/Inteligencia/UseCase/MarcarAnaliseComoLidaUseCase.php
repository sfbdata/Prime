<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\DTO\AnaliseOutput;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Exception\AnaliseNaoEncontradaException;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;

/**
 * "Marcar como lida" no menu do cartão. Idempotente (a entidade não troca quem leu primeiro).
 *
 * @throws AnaliseNaoEncontradaException
 */
final class MarcarAnaliseComoLidaUseCase
{
    public function __construct(
        private readonly AnaliseDeInteligenciaRepository $analises,
    ) {
    }

    public function executar(int $analiseId, int $pastaId, User $user, Tenant $tenant): AnaliseOutput
    {
        $analise = $this->analises->findOneDoTenantEAlvo($analiseId, $tenant, AnaliseDeInteligencia::ALVO_PASTA, $pastaId);
        if ($analise === null || $analise->estaExcluida()) {
            throw new AnaliseNaoEncontradaException($analiseId);
        }

        $analise->marcarLida($user);
        $this->analises->salvar($analise, true);

        return AnaliseOutput::fromEntity($analise);
    }
}
