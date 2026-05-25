<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PrioridadePasta;
use Doctrine\ORM\EntityManagerInterface;

final class AlterarPrioridadeUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function executar(Pasta $pasta, PrioridadePasta $prioridade): void
    {
        $pasta->setPrioridade($prioridade);
        $this->em->flush();
    }
}
