<?php

declare(strict_types=1);

namespace App\Voto\Service;

/**
 * A fronteira de transação dos UseCases da cédula do Voto. Existe como interface para o teste unitário não mockar o
 * EntityManager (mesmo modelo do App\ChatIa\Service\TransacaoDoChat).
 *
 * Diferente do `wrapInTransaction` do Doctrine, uma recusa de negócio lançada pelo trabalho desfaz a transação SEM
 * fechar o EntityManager: a cédula recusa voto (pauta fechada, voto repetido) como parte do fluxo normal.
 */
interface TransacaoDoVoto
{
    /**
     * Roda o trabalho numa transação, grava o que ficou pendente e confirma. Qualquer erro desfaz e é relançado.
     *
     * @template T
     *
     * @param callable(): T $trabalho
     *
     * @return T
     */
    public function executar(callable $trabalho): mixed;

    /** Grava agora o que está pendente, dentro da transação (quando a ordem entre INSERT e SQL direto importa). */
    public function gravar(): void;
}
