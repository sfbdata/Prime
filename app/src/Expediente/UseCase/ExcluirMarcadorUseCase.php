<?php

namespace App\Expediente\UseCase;

use App\Entity\Tenant\Tenant;
use App\Expediente\Entity\Marcador;
use App\Expediente\Repository\MarcadorRepository;
use Doctrine\ORM\EntityManagerInterface;

class ExcluirMarcadorUseCase
{
    public function __construct(
        private readonly MarcadorRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {}

    public function executar(int $id, Tenant $tenant): Marcador
    {
        $marcador = $this->repository->findPorTenant($id, $tenant);

        if ($marcador === null) {
            throw new \DomainException('Marcador não encontrado.');
        }

        $this->em->remove($marcador);
        $this->em->flush();

        return $marcador;
    }
}
