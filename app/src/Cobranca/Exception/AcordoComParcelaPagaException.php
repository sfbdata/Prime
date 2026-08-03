<?php

declare(strict_types=1);

namespace App\Cobranca\Exception;

/**
 * Cancelamento tentado sobre um Acordo cujas parcelas já receberam pagamento (spec
 * `cobranca-cancelar-acordo.md` §D4).
 *
 * O motivo é de DINHEIRO, não de integridade referencial: cancelado, o acordo deixa de ser vigente e
 * suas parcelas saem do exigível; a `CalculadoraSaldo` só abate alocações de obrigações EXIGÍVEIS
 * (`AlocacaoPagamentoRepository::totalAlocadoEmObrigacoes`), então o que já foi recebido **para de ser
 * descontado** e a dívida original volta cheia. O dinheiro continua registrado, mas some da conta — e
 * some em silêncio, que é o pior jeito de sumir.
 *
 * Decisão do dono (01/08): o viés de confirmação é a contabilidade, e a contabilidade não cancela
 * acordo com parcela paga — desfaz-se o pagamento primeiro.
 *
 * A recusa TEM saída desde a frente `cobranca-excluir-recebimento.md`: `cobranca_pagamento_excluir`
 * apaga o recebimento, devolve o valor ao saldo e reabre a obrigação que ele havia liquidado. Feito
 * isso, o cancelamento passa. (Até então isto era um beco sem saída — a spec §3.1 registrava a lacuna.)
 *
 * ⚠️ ROMPER não passa por aqui — e a rigor tem o MESMO efeito sobre o dinheiro, porque acordo rompido
 * também deixa de ser vigente. A diferença não é técnica, é de propósito: romper é um fato do mundo
 * (o devedor descumpriu) e BLOQUEAR seria errado, o gestor precisa poder registrar o que aconteceu.
 * A saída para os dois casos é a mesma: apagar o recebimento e relançá-lo onde ele deve abater.
 */
final class AcordoComParcelaPagaException extends \DomainException
{
    public function __construct(int $acordoId)
    {
        parent::__construct(sprintf(
            'Acordo %d não pode ser cancelado: há pagamento registrado nas parcelas dele. '
            . 'Exclua o recebimento na seção "O que já entrou" primeiro, senão o valor recebido deixa de abater a dívida. '
            . '(Se o acordo existiu e foi descumprido, o caso é romper, não cancelar.)',
            $acordoId,
        ));
    }
}
