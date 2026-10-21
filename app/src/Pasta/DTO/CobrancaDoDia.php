<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/** Uma cobrança que sai hoje: o item, o nível do aviso (1 = primeiro) e se é previsão descumprida. */
final readonly class CobrancaDoDia
{
    public function __construct(
        public PendenciaDoChecklist $pendencia,
        public int $nivel,
        public bool $quebrou,
        public int $diasPendente,
    ) {
    }
}
