<?php

declare(strict_types=1);

namespace App\ServiceDesk\Pagamento;

use App\Contribua\Service\CodigoPixEstatico;
use App\ServiceDesk\Enum\FormaDeRecarga;

/**
 * Adaptador padrão enquanto não há gateway: não cobra nada. No PIX devolve o copia e cola estático da
 * BlueJus com a referência da recarga como txid; a recarga fica pendente até o Master confirmar à mão
 * ("Chamados recebidos"). No cartão não há o que mostrar: só o aviso de que aguarda confirmação.
 */
final class RecargaManual implements ProvedorDeRecarga
{
    public function iniciar(PedidoDeRecarga $pedido): InstrucaoDePagamento
    {
        $copiaECola = $pedido->forma === FormaDeRecarga::Pix
            ? CodigoPixEstatico::gerar($pedido->referencia, $pedido->valorCentavos)
            : null;

        return new InstrucaoDePagamento($copiaECola, 'Aguardando a confirmação do pagamento pela equipe técnica.');
    }
}
