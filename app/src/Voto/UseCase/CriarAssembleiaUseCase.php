<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\AssembleiaInput;
use App\Voto\Entity\Assembleia;
use App\Voto\Repository\AssembleiaRepository;

/**
 * O gestor cria a assembleia de condomínio em rascunho: título (nome do evento) e peso do voto. Pautas e unidades
 * entram depois, nas telas próprias. Devolve o id para a tela seguir para as pautas.
 */
final class CriarAssembleiaUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
    ) {
    }

    public function executar(Tenant $tenant, AssembleiaInput $input, string $criadaPorNome): int
    {
        $assembleia = new Assembleia($tenant, $input->titulo, $input->pesoDoVoto(), $criadaPorNome);
        $this->assembleias->salvar($assembleia, flush: true);

        return (int) $assembleia->getId();
    }
}
