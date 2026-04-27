<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Pasta\Pasta;
use App\Entity\Pasta\PrioridadePasta;
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
