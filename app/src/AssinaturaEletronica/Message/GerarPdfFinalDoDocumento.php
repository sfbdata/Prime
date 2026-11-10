<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * O documento acabou de ser concluído: gerar e guardar o PDF final com selo no worker (E3-4), fora da requisição de
 * quem assinou por último. Despachada depois do COMMIT da conclusão; o escritório vai junto porque o worker não tem
 * sessão.
 *
 * `tentativa` é a do selo (etapa 8, E8-4): quem despacha começa na 1; o handler despacha de novo com a seguinte quando
 * o selo falha e tenta de novo, e a 4ª é a última.
 */
#[AsMessage('async')]
final readonly class GerarPdfFinalDoDocumento
{
    public function __construct(
        public int $documentoId,
        public int $tenantId,
        public int $tentativa = 1,
    ) {
    }
}
