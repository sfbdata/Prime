<?php

declare(strict_types=1);

namespace App\ChatIa\Service;

/**
 * A fronteira de transação dos UseCases do Chat I.A. Existe como interface para o teste unitário não mockar o
 * EntityManager: o trabalho roda numa transação e o `flush` vem depois dele.
 */
interface TransacaoDoChat
{
    /**
     * @template T
     *
     * @param callable(): T $trabalho
     *
     * @return T
     */
    public function executar(callable $trabalho): mixed;
}
