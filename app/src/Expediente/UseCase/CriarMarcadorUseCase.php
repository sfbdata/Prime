<?php

namespace App\Expediente\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Expediente\DTO\CriarMarcadorDTO;
use App\Expediente\Entity\Marcador;
use App\Expediente\Repository\MarcadorRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CriarMarcadorUseCase
{
    public function __construct(
        private readonly MarcadorRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {}

    public function executar(CriarMarcadorDTO $dto, User $usuario, Tenant $tenant): Marcador
    {
        $pai = null;

        if ($dto->paiId !== null) {
            $pai = $this->repository->findPorTenant($dto->paiId, $tenant);
            if ($pai === null) {
                throw new NotFoundHttpException('Marcador pai não encontrado.');
            }
        }

        $marcador = new Marcador($dto->nome, $tenant, $usuario, $pai);

        if ($dto->cor !== null) {
            $marcador->setCor($dto->cor);
        }

        $this->em->persist($marcador);
        $this->em->flush();

        return $marcador;
    }
}
