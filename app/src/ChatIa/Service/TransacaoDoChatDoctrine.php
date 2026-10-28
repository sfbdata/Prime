<?php

declare(strict_types=1);

namespace App\ChatIa\Service;

use Doctrine\ORM\EntityManagerInterface;

/** A transação do Chat I.A sobre o EntityManager: `wrapInTransaction` faz o `flush` e fecha o EM em qualquer falha. */
final class TransacaoDoChatDoctrine implements TransacaoDoChat
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function executar(callable $trabalho): mixed
    {
        return $this->entityManager->wrapInTransaction($trabalho);
    }
}
