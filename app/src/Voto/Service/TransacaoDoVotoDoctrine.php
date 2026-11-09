<?php

declare(strict_types=1);

namespace App\Voto\Service;

use Doctrine\ORM\EntityManagerInterface;

/** A transação da cédula do Voto sobre o EntityManager, aberta à mão para a recusa de negócio não fechá-lo. */
final class TransacaoDoVotoDoctrine implements TransacaoDoVoto
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function executar(callable $trabalho): mixed
    {
        $this->em->beginTransaction();
        try {
            $resultado = $trabalho();
            $this->em->flush();
            $this->em->commit();

            return $resultado;
        } catch (\Throwable $erro) {
            // Um flush que falhou já desfez o próprio nível; aqui sai o nível desta transação.
            if ($this->em->getConnection()->isTransactionActive()) {
                $this->em->rollback();
            }
            throw $erro;
        }
    }

    public function gravar(): void
    {
        $this->em->flush();
    }
}
