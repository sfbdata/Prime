<?php

namespace App\Expediente\UseCase;

use App\Entity\Auth\User;
use App\Expediente\Entity\PastaOrganizadora;
use App\Expediente\Repository\PastaOrganizadoraRepository;
use Doctrine\ORM\EntityManagerInterface;

class EditarPastaOrganizadoraUseCase
{
    private const CORES_PERMITIDAS = [
        '#fde8e8', '#fde8f5', '#ede8fd', '#e8eafd', '#e8f4fd',
        '#e8fdf0', '#fdf6e8', '#fde8d0', '#f0f0f0', null,
    ];

    public function __construct(
        private readonly PastaOrganizadoraRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {}

    public function executar(int $id, string $nome, ?string $cor, User $usuario): PastaOrganizadora
    {
        $pasta = $this->repository->findPorTenant($id, $usuario->getTenant());

        if ($pasta === null) {
            throw new \DomainException('Marcador não encontrado.');
        }

        if ($cor !== null && !in_array($cor, self::CORES_PERMITIDAS, true)) {
            throw new \DomainException('Cor inválida.');
        }

        $pasta->setNome($nome);
        $pasta->setCor($cor);
        $this->em->flush();

        return $pasta;
    }
}
