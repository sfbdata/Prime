<?php

namespace App\Expediente\UseCase;

use App\Entity\Auth\User;
use App\Expediente\Entity\PastaOrganizadora;
use App\Expediente\Repository\PastaOrganizadoraRepository;
use Doctrine\ORM\EntityManagerInterface;

class ExcluirPastaOrganizadoraUseCase
{
    public function __construct(
        private readonly PastaOrganizadoraRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {}

    public function executar(int $id, User $usuario): PastaOrganizadora
    {
        $pasta = $this->repository->findPorTenant($id, $usuario->getTenant());

        if ($pasta === null) {
            throw new \DomainException('Marcador não encontrado.');
        }

        $this->em->remove($pasta);
        $this->em->flush();

        return $pasta;
    }
}
