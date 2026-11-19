<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\AssembleiaInput;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Service\CnpjDaEntidade;
use App\Voto\Service\SerializacaoDaConfiguracao;

/** Título, peso do voto, cenário, entidade e CNPJ da assembleia, só em rascunho (a entidade recusa depois de publicada). */
final class EditarAssembleiaUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly SerializacaoDaConfiguracao $serializacao,
        private readonly CnpjDaEntidade $cnpjs,
    ) {
    }

    public function executar(Tenant $tenant, int $assembleiaId, AssembleiaInput $input): void
    {
        $this->serializacao->executar($tenant, $assembleiaId, function () use ($tenant, $assembleiaId, $input): void {
            $this->executarComConfiguracaoReservada($tenant, $assembleiaId, $input);
        });
    }

    private function executarComConfiguracaoReservada(Tenant $tenant, int $assembleiaId, AssembleiaInput $input): void
    {
        $assembleia = $this->assembleias->buscarDoEscritorio($assembleiaId, $tenant)
            ?? throw new AssembleiaNaoEncontradaException();
        $cnpj = $this->cnpjs->normalizar($input->cnpj);
        $assembleia->editar($input->titulo, $input->pesoDoVoto());
        $assembleia->definirCenario($input->cenarioDaAssembleia());
        $assembleia->definirSimulacao($input->simulacao);
        $assembleia->identificarEntidade($input->entidade, $cnpj);
        $assembleia->definirRotulos($input->rotuloUnidade, $input->rotuloBloco);
        $this->assembleias->salvar($assembleia, flush: true);
    }
}
