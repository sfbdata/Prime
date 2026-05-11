<?php

namespace App\Expediente\UseCase;

use App\Entity\Tenant\Tenant;
use App\Expediente\Entity\Marcador;
use App\Expediente\Repository\MarcadorRepository;
use Doctrine\ORM\EntityManagerInterface;

class EditarMarcadorUseCase
{
    private const CORES_PERMITIDAS = [
        '#fde8e8', '#fde8f5', '#ede8fd', '#e8eafd', '#e8f4fd',
        '#e8fdf0', '#fdf6e8', '#fde8d0', '#f0f0f0', null,
    ];

    public function __construct(
        private readonly MarcadorRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {}

    public function executar(int $id, string $nome, ?string $cor, Tenant $tenant): Marcador
    {
        $marcador = $this->repository->findPorTenant($id, $tenant);

        if ($marcador === null) {
            throw new \DomainException('Marcador não encontrado.');
        }

        if ($cor !== null && !in_array($cor, self::CORES_PERMITIDAS, true)) {
            throw new \DomainException('Cor inválida.');
        }

        $marcador->setNome($nome);
        $marcador->setCor($cor);
        $this->em->flush();

        return $marcador;
    }
}
