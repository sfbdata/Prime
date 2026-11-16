<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\ResultadoDoLote;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\AssembleiaRepository;

/**
 * Exclui os rascunhos nunca publicados marcados (seleção em lote do desenho 18, A6). Cada um sai pelo
 * {@see ExcluirRascunhoDaAssembleiaUseCase}; a votação publicada nunca é excluída (fica e entra nas "mantidas": use
 * Arquivar). Uma assembleia de outro escritório recusa o lote inteiro (404), antes de apagar qualquer uma.
 */
final class ExcluirRascunhosEmLoteUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly ExcluirRascunhoDaAssembleiaUseCase $excluir,
    ) {
    }

    /** @param list<int> $ids */
    public function executar(Tenant $tenant, array $ids): ResultadoDoLote
    {
        foreach ($ids as $id) {
            if (null === $this->assembleias->buscarDoEscritorio($id, $tenant)) {
                throw new AssembleiaNaoEncontradaException();
            }
        }
        $feitas = 0;
        $mantidas = 0;
        foreach ($ids as $id) {
            try {
                $this->excluir->executar($tenant, $id);
                ++$feitas;
            } catch (OperacaoInvalidaNaAssembleiaException) {
                ++$mantidas;
            }
        }

        return new ResultadoDoLote($feitas, $mantidas);
    }
}
