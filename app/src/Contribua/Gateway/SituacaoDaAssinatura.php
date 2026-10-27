<?php

declare(strict_types=1);

namespace App\Contribua\Gateway;

/** A situação de uma assinatura de espaço, na língua do BlueJus (cada adaptador traduz a do gateway). */
enum SituacaoDaAssinatura: string
{
    /** Criada, sem pagamento confirmado ainda (o cartão está sendo processado ou o pagador não terminou). */
    case Aguardando = 'aguardando';
    /** Paga: o espaço do plano vale até `pagaAte`. */
    case Ativa = 'ativa';
    /** Cobrança vencida sem pagamento: entra a carência e depois volta ao Free. */
    case Inadimplente = 'inadimplente';
    /** Cancelada pelo escritório, pelo Master ou pelo gateway: vale até o fim do mês pago. */
    case Cancelada = 'cancelada';
}
