<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\MateriaFavoritaDeModelo;
use App\Pasta\Enum\MateriaDeModelo;
use App\Pasta\Repository\MateriaFavoritaDeModeloRepository;

/** Liga ou desliga a estrela de uma matéria para o usuário. Devolve se ficou favorita. */
final class AlternarMateriaFavoritaUseCase
{
    public function __construct(
        private readonly MateriaFavoritaDeModeloRepository $favoritaRepository,
    ) {
    }

    public function executar(MateriaDeModelo $materia, User $usuario, Tenant $tenant): bool
    {
        $atual = $this->favoritaRepository->buscar($usuario, $materia);
        if ($atual !== null) {
            $this->favoritaRepository->remover($atual, flush: true);

            return false;
        }

        $this->favoritaRepository->salvar(new MateriaFavoritaDeModelo($tenant, $usuario, $materia), flush: true);

        return true;
    }
}
