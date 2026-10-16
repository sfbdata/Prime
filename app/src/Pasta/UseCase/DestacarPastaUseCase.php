<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Permission\AccessRequest;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDestaque;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Exception\SemPermissaoParaVerPastaException;
use App\Pasta\Repository\PastaDestaqueRepository;
use Doctrine\ORM\EntityManagerInterface;
use App\Service\PermissionChecker;

/**
 * "Destacar linha" do botão direito da Lista, desenho 1.1 (E12): pinta a linha da pasta numa das 6 cores,
 * ou tira o destaque (`$cor = null`). Preferência de quem olha, como o favorito: basta VER a pasta, e o
 * colega não vê a cor. Devolve a cor final.
 */
final class DestacarPastaUseCase
{
    public function __construct(
        private readonly PastaDestaqueRepository $repository,
        private readonly PermissionChecker $permissionChecker,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function executar(Pasta $pasta, ?string $cor, User $usuario, Tenant $tenant): ?string
    {
        if ($pasta->getTenant() !== $tenant) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }

        if (!$this->permissionChecker->canAccessResource($usuario, $tenant, AccessRequest::RESOURCE_PASTA, (int) $pasta->getId(), AccessRequest::ACTION_VIEW)) {
            throw new SemPermissaoParaVerPastaException('Sem permissão para ver esta pasta.');
        }

        if ($cor !== null && !in_array($cor, PastaDestaque::CORES, true)) {
            throw new \DomainException('Cor de destaque inválida.');
        }

        $existente = $this->repository->buscarDoUsuario($pasta, $usuario, $tenant);

        if ($cor === null) {
            if ($existente !== null) {
                $this->em->remove($existente);
                $this->em->flush();
            }

            return null;
        }

        if ($existente !== null) {
            $existente->definirCor($cor);
        } else {
            $this->em->persist(new PastaDestaque($tenant, $usuario, $pasta, $cor));
        }
        $this->em->flush();

        return $cor;
    }
}
