<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * O documento acabou de ser concluído: gerar e guardar o PDF final com selo no worker (E3-4), fora da requisição de
 * quem assinou por último. Despachada depois do COMMIT da conclusão; o escritório vai junto porque o worker não tem
 * sessão.
 */
#[AsMessage('async')]
final readonly class GerarPdfFinalDoDocumento
{
    public function __construct(
        public int $documentoId,
        public int $tenantId,
    ) {
    }
}
