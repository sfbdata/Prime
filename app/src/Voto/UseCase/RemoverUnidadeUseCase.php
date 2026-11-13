<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\UnidadeDaAssembleiaRepository;
use App\Voto\Service\SerializacaoDaConfiguracao;

/** Tira uma unidade da lista, enquanto nenhuma pauta foi aberta. O link dela deixa de existir. */
final class RemoverUnidadeUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly UnidadeDaAssembleiaRepository $unidades,
        private readonly SerializacaoDaConfiguracao $serializacao,
    ) {
    }

    public function executar(Tenant $tenant, int $assembleiaId, int $unidadeId): void
    {
        $this->serializacao->executar($tenant, $assembleiaId, function () use ($tenant, $assembleiaId, $unidadeId): void {
            $this->executarComConfiguracaoReservada($tenant, $assembleiaId, $unidadeId);
        });
    }

    private function executarComConfiguracaoReservada(Tenant $tenant, int $assembleiaId, int $unidadeId): void
    {
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();
        $assembleia->exigirUnidadesAlteraveis();
        $unidade = $this->unidades->buscarDaAssembleia($unidadeId, $assembleia) ?? throw new AssembleiaNaoEncontradaException();
        $assembleia->getUnidades()->removeElement($unidade);
        $this->unidades->remover($unidade, flush: true);
    }
}
