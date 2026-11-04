<?php

declare(strict_types=1);

namespace App\Sync\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * "Reconheça as pastas que já estão no Drive do escritório Y" (spec `docs/specs/drive-reconhecimento.md`). Vai para o
 * worker porque um acervo grande pede muitas leituras do Drive e não cabe no tempo de uma página.
 */
#[AsMessage('async')]
final readonly class ReconhecerPastasDoDrive
{
    public function __construct(
        public int $tenantId,
    ) {
    }
}
