<?php

declare(strict_types=1);

namespace App\Inteligencia\Exception;

/**
 * Não há o que analisar. No Push: a pasta não tem processo vinculado, ou o processo não tem
 * publicação nem movimentação. Nos agentes da pasta: nenhuma das seções que o agente lê tem uma
 * linha sequer. Recusado na solicitação (nada é persistido) — gastar cota com prompt vazio não faz
 * sentido, e o worker guarda a mesma regra para o caso de o contexto esvaziar entre o pedido e o
 * processamento.
 */
final class ContextoVazioException extends \DomainException
{
    public function __construct(
        ?string $mensagem = null,
        private readonly string $motivo = 'sem_movimentacoes',
    ) {
        parent::__construct($mensagem ?? 'Esta pasta ainda não tem movimentações para analisar.');
    }

    public static function semDadosParaOAgente(): self
    {
        return new self('Esta pasta ainda não tem dados para este agente analisar.', 'sem_dados');
    }

    public function motivo(): string
    {
        return $this->motivo;
    }
}
