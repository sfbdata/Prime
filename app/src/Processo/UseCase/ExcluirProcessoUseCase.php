<?php

declare(strict_types=1);

namespace App\Processo\UseCase;

use App\Pasta\Repository\PastaRepository;
use App\Processo\Entity\Processo;
use Doctrine\ORM\EntityManagerInterface;

final class ExcluirProcessoUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PastaRepository $pastaRepository,
    ) {}

    public function executar(Processo $processo): int
    {
        $pastas = $this->pastaRepository->findByProcesso($processo);
        foreach ($pastas as $pasta) {
            $pasta->setProcesso(null);
        }
        $this->em->remove($processo);
        $this->em->flush();

        return count($pastas);
    }
}
