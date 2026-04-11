<?php

namespace App\Expediente\UseCase;

use App\Entity\Auth\User;
use App\Expediente\DTO\CriarPastaOrganizadoraDTO;
use App\Expediente\Entity\PastaOrganizadora;
use App\Expediente\Repository\PastaOrganizadoraRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CriarPastaOrganizadoraUseCase
{
    public function __construct(
        private readonly PastaOrganizadoraRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {}

    public function executar(CriarPastaOrganizadoraDTO $dto, User $usuario): PastaOrganizadora
    {
        $tenant = $usuario->getTenant();
        $pai    = null;

        if ($dto->paiId !== null) {
            $pai = $this->repository->findPorTenant($dto->paiId, $tenant);
            if ($pai === null) {
                throw new NotFoundHttpException('Pasta pai não encontrada.');
            }
        }

        $pasta = new PastaOrganizadora($dto->nome, $tenant, $usuario, $pai);

        $this->em->persist($pasta);
        $this->em->flush();

        return $pasta;
    }
}
