<?php

declare(strict_types=1);

namespace App\ServiceDesk\Service;

use Doctrine\ORM\EntityManagerInterface;

final class TransacaoDoRazaoNoDoctrine implements TransacaoDoRazao
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function emTransacao(\Closure $trabalho): mixed
    {
        return $this->em->wrapInTransaction(static fn (): mixed => $trabalho());
    }
}
