<?php

declare(strict_types=1);

namespace App\ServiceDesk\Pagamento;

/**
 * Adaptador padrão enquanto não há gateway: não cobra nada, deixa a recarga pendente para o Master
 * confirmar à mão ("Chamados recebidos"). O PIX copia e cola do desenho entra na fatia da recarga.
 */
final class RecargaManual implements ProvedorDeRecarga
{
    public function iniciar(PedidoDeRecarga $pedido): InstrucaoDePagamento
    {
        return new InstrucaoDePagamento(null, 'Aguardando a confirmação do pagamento pela equipe técnica.');
    }
}
