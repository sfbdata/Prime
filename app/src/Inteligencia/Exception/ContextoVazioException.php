<?php

declare(strict_types=1);

namespace App\Inteligencia\Exception;

/**
 * Não há o que analisar: a pasta não tem processo vinculado, ou o processo não tem publicação nem
 * movimentação. Recusado na solicitação (nada é persistido) — gastar cota com prompt vazio não faz
 * sentido, e o worker guarda a mesma regra para o caso de o contexto esvaziar entre o pedido e o
 * processamento.
 */
final class ContextoVazioException extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Esta pasta ainda não tem movimentações para analisar.');
    }

    public function motivo(): string
    {
        return 'sem_movimentacoes';
    }
}
