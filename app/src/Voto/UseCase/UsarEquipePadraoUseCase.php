<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Voto\Repository\MembroDaEquipeRepository;
use App\Voto\Service\EdicaoDaEquipe;
use App\Voto\Service\SerializacaoDaConfiguracao;

/**
 * "Usar a equipe padrão": apaga as linhas da equipe própria e a assembleia volta a valer pelo módulo `voto`, como se
 * nunca tivesse tido equipe. Só a Administração, só em rascunho. Devolve quantas linhas apagou.
 */
final class UsarEquipePadraoUseCase
{
    public function __construct(
        private readonly EdicaoDaEquipe $edicao,
        private readonly MembroDaEquipeRepository $membros,
        private readonly SerializacaoDaConfiguracao $serializacao,
    ) {
    }

    /**
     * @throws \App\Voto\Exception\EquipeSoDaAdministracaoException
     * @throws \App\Voto\Exception\AssembleiaNaoEncontradaException
     * @throws \App\Voto\Exception\OperacaoInvalidaNaAssembleiaException
     */
    public function executar(User $ator, Tenant $tenant, int $assembleiaId): int
    {
        return $this->serializacao->executar($tenant, $assembleiaId, function () use ($ator, $tenant, $assembleiaId): int {
            $assembleia = $this->edicao->assembleiaEditavel($ator, $tenant, $assembleiaId);
            $linhas = $this->membros->daAssembleia($assembleia);
            $this->membros->removerVarios($linhas);

            return \count($linhas);
        });
    }
}
