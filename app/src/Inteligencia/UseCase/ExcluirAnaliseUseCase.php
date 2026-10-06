<?php

declare(strict_types=1);

namespace App\Inteligencia\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Exception\AnaliseNaoEncontradaException;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;

/**
 * "Excluir esta análise": soft delete — some da lista, a linha e a trilha ficam (a cota já foi
 * gasta; a auditoria precisa do rastro). As movimentações oficiais nunca são tocadas.
 *
 * @throws AnaliseNaoEncontradaException
 */
final class ExcluirAnaliseUseCase
{
    public function __construct(
        private readonly AnaliseDeInteligenciaRepository $analises,
    ) {
    }

    public function executar(int $analiseId, int $pastaId, User $user, Tenant $tenant): void
    {
        $analise = $this->analises->findOneDoTenantEAlvo($analiseId, $tenant, AnaliseDeInteligencia::ALVO_PASTA, $pastaId);
        if ($analise === null) {
            throw new AnaliseNaoEncontradaException($analiseId);
        }

        $analise->excluir($user);
        $this->analises->salvar($analise, true);
    }
}
