<?php

declare(strict_types=1);

namespace App\Contribua\Event;

use App\Contribua\DTO\RetratoDoEspaco;
use App\Contribua\Enum\FaixaDoEspaco;

/**
 * O uso de um escritório subiu de faixa (Atenção, Alerta, Crítico, Bloqueio) com um envio.
 *
 * Evento de domínio, disparado pelo `ArmazenamentoComCota` logo depois da gravação. Quem manda os
 * e-mails do desenho 05 ("Comercial e e-mails": espaço em 90% e em 100%) só ouve este evento; o
 * Contribua não envia e-mail direto.
 *
 * Disparado antes do COMMIT de quem gravou: o ouvinte não pode supor que o documento já está no
 * banco, e uma falha dele não pode desfazer o envio (ouça e enfileire).
 */
final readonly class EspacoAtingiuFaixa
{
    public function __construct(
        public int $tenantId,
        public FaixaDoEspaco $faixa,
        public FaixaDoEspaco $faixaAnterior,
        public RetratoDoEspaco $retrato,
    ) {
    }
}
