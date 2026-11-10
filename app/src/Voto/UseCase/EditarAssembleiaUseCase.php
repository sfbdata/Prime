<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\AssembleiaInput;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Repository\AssembleiaRepository;

/** Título e peso do voto da assembleia, só em rascunho (a entidade recusa depois de publicada). */
final class EditarAssembleiaUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
    ) {
    }

    public function executar(Tenant $tenant, int $assembleiaId, AssembleiaInput $input): void
    {
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();
        $assembleia->editar($input->titulo, $input->pesoDoVoto());
        $this->assembleias->salvar($assembleia, flush: true);
    }
}
