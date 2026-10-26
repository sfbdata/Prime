<?php

declare(strict_types=1);

namespace App\ServiceDesk\Pagamento;

/**
 * Ponto de integração da recarga de saldo: quem cobra o escritório. O razão (`MovimentoDeSaldo`)
 * é do ServiceDesk e não conhece gateway. Um adaptador só faz duas coisas:
 *
 * 1. `iniciar()`: registra a cobrança na operadora e devolve o que a tela mostra. O UseCase grava o pendente
 *    ANTES de chamar. Lance `CobrancaNaoCriada` só quando for certo que nada foi cobrado (o pendente é
 *    desfeito); qualquer outra exceção é tratada como ambígua (timeout) e o pendente fica.
 * 2. Avisar o razão quando o pagamento cair, chamando `ConfirmarRecargaDeSaldoUseCase` com a
 *    `referencia` do pedido. A confirmação é idempotente: o webhook pode repetir à vontade.
 *
 * Valor sempre em centavos. Dado de cartão nunca passa por aqui.
 */
interface ProvedorDeRecarga
{
    public function iniciar(PedidoDeRecarga $pedido): InstrucaoDePagamento;
}
