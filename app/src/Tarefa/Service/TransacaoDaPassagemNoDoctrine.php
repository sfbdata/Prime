<?php

declare(strict_types=1);

namespace App\Tarefa\Service;

use App\Entity\Tarefa\Tarefa;
use Doctrine\ORM\EntityManagerInterface;

/**
 * {@see TransacaoDaPassagem} pelo EntityManager. A meta nova é numerada como no `GravarMetaNovaUseCase`: a trava
 * por escritório da `NumeracaoDeMetas` só segura até o COMMIT desta mesma transação.
 */
final class TransacaoDaPassagemNoDoctrine implements TransacaoDaPassagem
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NumeracaoDeMetas $numeracao,
    ) {
    }

    public function emTransacao(\Closure $trabalho): mixed
    {
        return $this->em->wrapInTransaction(static fn (): mixed => $trabalho());
    }

    public function gravarMetaNova(Tarefa $nova): void
    {
        $nova->numerar($this->numeracao->reservar((int) $nova->getTenant()?->getId()));
        $this->em->persist($nova);
        $this->em->flush();
    }
}
