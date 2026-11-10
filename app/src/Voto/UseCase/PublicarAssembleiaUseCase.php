<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Repository\AssembleiaRepository;

/**
 * Publica a assembleia: exige ao menos uma pauta com duas opções e uma unidade apta (regra da entidade) e trava as
 * pautas. Depois disso a mesa abre e encerra cada pauta.
 */
final class PublicarAssembleiaUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
    ) {
    }

    public function executar(Tenant $tenant, int $assembleiaId, ?\DateTimeImmutable $agora = null): void
    {
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();
        $assembleia->publicar($agora);
        $this->assembleias->salvar($assembleia, flush: true);
    }
}
