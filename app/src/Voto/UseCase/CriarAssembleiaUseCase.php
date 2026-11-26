<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Tenant\Tenant;
use App\Voto\DTO\AssembleiaInput;
use App\Voto\Entity\Assembleia;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Service\CnpjDaEntidade;

/**
 * O gestor cria a assembleia de condomínio em rascunho: título (nome do evento), peso do voto e, se informados, a entidade (nome e CNPJ). Pautas e unidades
 * entram depois, nas telas próprias. Devolve o id para a tela seguir para as pautas.
 */
final class CriarAssembleiaUseCase
{
    public function __construct(
        private readonly AssembleiaRepository $assembleias,
        private readonly CnpjDaEntidade $cnpjs,
    ) {
    }

    public function executar(Tenant $tenant, AssembleiaInput $input, string $criadaPorNome): int
    {
        $assembleia = new Assembleia($tenant, $input->titulo, $input->pesoDoVoto(), $criadaPorNome, $input->cenarioDaAssembleia());
        $assembleia->definirSimulacao($input->simulacao);
        $assembleia->identificarEntidade($input->entidade, $this->cnpjs->normalizar($input->cnpj));
        $assembleia->definirRotulos($input->rotuloUnidade, $input->rotuloBloco);
        $assembleia->definirLinkDaSalaVirtual($input->linkSalaVirtual);
        $this->assembleias->salvar($assembleia, flush: true);

        return (int) $assembleia->getId();
    }
}
