<?php

declare(strict_types=1);

namespace App\Contribua\Event;

use App\Contribua\DTO\RetratoDoEspaco;

/**
 * Um envio de arquivo foi recusado porque o escritório está sem espaço (bloqueio ligado). É de onde sai o
 * aviso "Espaço esgotado: novos envios estão pausados" do desenho 05 (frente "Comercial e e-mails"): com
 * o bloqueio ligado, o envio que estouraria é recusado antes, e a faixa Bloqueio quase nunca é atingida
 * exatamente.
 *
 * Despachado NA HORA, sem esperar o fim do trabalho: a recusa é o próprio fato (o trabalho falha de
 * propósito com 422) e não há nada a desfazer.
 */
final readonly class EnvioRecusadoPorEspaco
{
    public function __construct(
        public int $tenantId,
        public RetratoDoEspaco $retrato,
    ) {
    }
}
