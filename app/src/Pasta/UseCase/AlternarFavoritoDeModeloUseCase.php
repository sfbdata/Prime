<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\ModeloDePeca;
use App\Pasta\Entity\ModeloDePecaFavorito;
use App\Pasta\Repository\ModeloDePecaFavoritoRepository;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/** Liga ou desliga a estrela de um modelo para o usuário. Devolve se ficou favorito. */
final class AlternarFavoritoDeModeloUseCase
{
    public function __construct(
        private readonly ModeloDePecaFavoritoRepository $favoritoRepository,
    ) {
    }

    public function executar(ModeloDePeca $modelo, User $usuario, Tenant $tenant): bool
    {
        if ($modelo->getTenant() !== $tenant) {
            throw new AccessDeniedException('Modelo não pertence ao escritório.');
        }

        $atual = $this->favoritoRepository->buscar($usuario, $modelo);
        if ($atual !== null) {
            $this->favoritoRepository->remover($atual, flush: true);

            return false;
        }

        $this->favoritoRepository->salvar(new ModeloDePecaFavorito($tenant, $usuario, $modelo), flush: true);

        return true;
    }
}
