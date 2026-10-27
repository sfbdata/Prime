<?php

declare(strict_types=1);

namespace App\ServiceDesk\Enum;

/**
 * Desfecho de `ConfirmarRecargaDeSaldoUseCase`. Vira valor (e não exceção) porque o
 * `wrapInTransaction` do ORM fecha o EntityManager em qualquer exceção, e o webhook precisa do banco
 * depois para responder e registrar.
 */
enum ResultadoDaConfirmacao: string
{
    case Confirmada = 'confirmada';
    case JaConfirmada = 'ja_confirmada';
    /** Valor pago MENOR que o pedido: nada creditado, a recarga segue pendente e a divergência fica gravada. */
    case ValorDivergente = 'valor_divergente';
    /** O mesmo pagamento (idExterno) já confirmou outra recarga: nada creditado. */
    case PagamentoRepetido = 'pagamento_repetido';
    case NaoEncontrada = 'nao_encontrada';
}
