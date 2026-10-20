<?php

declare(strict_types=1);

namespace App\Contribua\Event;

use App\Contribua\DTO\RetratoDoEspaco;
use App\Contribua\Enum\FaixaDoEspaco;

/**
 * O uso de um escritório subiu de faixa (Atenção, Alerta, Crítico, Bloqueio) com um envio.
 *
 * Evento de domínio, gerado pelo `ArmazenamentoComCota` na gravação. Quem manda os
 * e-mails do desenho 05 ("Comercial e e-mails": espaço em 90% e em 100%) só ouve este evento; o
 * Contribua não envia e-mail direto.
 *
 * Despachado DEPOIS do commit de quem gravou (`EventosDoEspacoAposConfirmar`): envio desfeito por
 * rollback ou erro não gera aviso.
 */
final readonly class EspacoAtingiuFaixa
{
    public function __construct(
        public int $tenantId,
        public FaixaDoEspaco $faixa,
        public FaixaDoEspaco $faixaAnterior,
        public RetratoDoEspaco $retrato,
        public ?int $percentualAnterior = null,
    ) {
    }
}
