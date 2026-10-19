<?php

declare(strict_types=1);

namespace App\Contribua\Gateway;

/**
 * Opcional: o gateway emite a nota fiscal mensal da assinatura (garantia do desenho 08, "Nota fiscal ·
 * Enviada por e-mail todo mês"). Hoje só o Asaas (NFS-e da assinatura, §6 da spec). Chamado uma vez,
 * quando a assinatura do gateway se liga a um pedido do BlueJus.
 */
interface EmiteNotaFiscalDaAssinatura
{
    /** Liga a nota mensal. Sem configuração fiscal no ambiente, não faz nada. @throws FalhaNoGateway */
    public function configurarNotaFiscalMensal(string $idDaAssinatura): void;
}
