<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\CriarPastaDTO;
use Doctrine\ORM\EntityManagerInterface;

final class CriarPastaUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function executar(CriarPastaDTO $dto, User $criadoPor, Tenant $tenant): Pasta
    {
        $nup = trim($dto->nup);

        if ($nup === '') {
            throw new \InvalidArgumentException('O NUP é obrigatório.');
        }

        // NUP pode repetir (sync Drive): a identidade é o driveFolderId, não o NUP.
        $pasta = new Pasta();
        $pasta->setNup($nup);
        $pasta->setNomeCliente($dto->nomeCliente);
        $pasta->setNomeAcao($dto->nomeAcao);
        $pasta->setCriadoPor($criadoPor);
        $pasta->setTenant($tenant);

        $this->em->persist($pasta);
        $this->em->flush();

        return $pasta;
    }
}
