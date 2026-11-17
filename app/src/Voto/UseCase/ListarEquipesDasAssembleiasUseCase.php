<?php

declare(strict_types=1);

namespace App\Voto\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Voto\DTO\EquipeDaAssembleia;
use App\Voto\DTO\LinhaDaEquipe;
use App\Voto\Exception\EquipeSoDaAdministracaoException;
use App\Voto\Repository\AssembleiaRepository;
use App\Voto\Repository\MembroDaEquipeRepository;
use App\Voto\Service\AcessoAAssembleia;

/**
 * Os grupos da tela Perfis: uma entrada por assembleia do escritório (menos as arquivadas), com a equipe própria dela,
 * em duas consultas (assembleias e equipes), sem N+1. Só a Administração vê a tela.
 */
final class ListarEquipesDasAssembleiasUseCase
{
    public function __construct(
        private readonly AcessoAAssembleia $acesso,
        private readonly AssembleiaRepository $assembleias,
        private readonly MembroDaEquipeRepository $membros,
    ) {
    }

    /**
     * @return list<EquipeDaAssembleia>
     *
     * @throws EquipeSoDaAdministracaoException
     */
    public function executar(User $ator, Tenant $tenant): array
    {
        if (!$this->acesso->ehAdministracao($ator, $tenant)) {
            throw new EquipeSoDaAdministracaoException();
        }

        $porAssembleia = [];
        foreach ($this->membros->doEscritorio($tenant) as $membro) {
            $porAssembleia[(int) $membro->getAssembleia()->getId()][] = LinhaDaEquipe::de($membro);
        }

        $grupos = [];
        foreach ($this->assembleias->listarDoEscritorio($tenant) as $assembleia) {
            if ($assembleia->arquivada()) {
                continue;
            }
            $id = (int) $assembleia->getId();
            $grupos[] = new EquipeDaAssembleia($id, $assembleia->getTitulo(), $assembleia->getCenario(), $assembleia->podeAlterarPautas(), $porAssembleia[$id] ?? []);
        }

        return $grupos;
    }
}
