<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Voto\Exception\AssembleiaNaoEncontradaException;
use App\Voto\Repository\MembroDaEquipeRepository;
use App\Voto\Service\EdicaoDaEquipe;
use App\Voto\Service\SerializacaoDaConfiguracao;

/**
 * Tira uma linha da equipe (a lixeira da tela Perfis). Só a Administração, só em rascunho. Se for a última, a assembleia
 * volta à equipe padrão. A Administração não está na tabela: não há o que remover dela.
 */
final class RemoverMembroDaEquipeUseCase
{
    public function __construct(
        private readonly EdicaoDaEquipe $edicao,
        private readonly MembroDaEquipeRepository $membros,
        private readonly SerializacaoDaConfiguracao $serializacao,
    ) {
    }

    /**
     * @throws \App\Voto\Exception\EquipeSoDaAdministracaoException
     * @throws AssembleiaNaoEncontradaException
     * @throws \App\Voto\Exception\OperacaoInvalidaNaAssembleiaException
     */
    public function executar(User $ator, Tenant $tenant, int $assembleiaId, int $membroId): void
    {
        $this->serializacao->executar($tenant, $assembleiaId, function () use ($ator, $tenant, $assembleiaId, $membroId): void {
            $assembleia = $this->edicao->assembleiaEditavel($ator, $tenant, $assembleiaId);
            $membro = $this->membros->buscarDaAssembleia($membroId, $assembleia) ?? throw new AssembleiaNaoEncontradaException();
            $this->membros->remover($membro, flush: true);
        });
    }
}
