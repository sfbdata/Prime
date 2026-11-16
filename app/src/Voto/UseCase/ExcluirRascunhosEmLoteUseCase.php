<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\ResultadoDoLote;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Exception\OperacaoInvalidaNaAssembleiaException;
use App\Voto\Repository\AssembleiaRepository;

/**
 * Exclui os rascunhos nunca publicados e as simulações marcados (seleção em lote do desenho 18, A6). O rascunho sai pelo
 * {@see ExcluirRascunhoDaAssembleiaUseCase} e a simulação pelo {@see ExcluirSimulacaoDaAssembleiaUseCase}; a votação
 * real publicada nunca é excluída (fica e entra nas "mantidas": use Arquivar). Uma assembleia de outro escritório recusa o lote inteiro (404), antes de apagar qualquer uma.
 */
final class ExcluirRascunhosEmLoteUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly ExcluirRascunhoDaAssembleiaUseCase $excluir,
        private readonly ExcluirSimulacaoDaAssembleiaUseCase $excluirSimulacao,
    ) {
    }

    /** @param list<int> $ids */
    public function executar(Tenant $tenant, array $ids): ResultadoDoLote
    {
        $simulacoes = [];
        foreach ($ids as $id) {
            $assembleia = $this->assembleias->buscarDoEscritorio($id, $tenant) ?? throw new AssembleiaNaoEncontradaException();
            $simulacoes[$id] = $assembleia->isSimulacao();
        }
        $feitas = 0;
        $mantidas = 0;
        foreach ($ids as $id) {
            try {
                $simulacoes[$id] ? $this->excluirSimulacao->executar($tenant, $id) : $this->excluir->executar($tenant, $id);
                ++$feitas;
            } catch (OperacaoInvalidaNaAssembleiaException|AssembleiaNaoEncontradaException) {
                // Excluída por outra pessoa no meio do lote: conta como mantida, sem desfazer as já feitas.
                ++$mantidas;
            }
        }

        return new ResultadoDoLote($feitas, $mantidas);
    }
}
