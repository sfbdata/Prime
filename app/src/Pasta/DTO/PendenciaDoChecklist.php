<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/** Um item pendente do checklist, do jeito que o plano de cobrança precisa enxergar. */
final readonly class PendenciaDoChecklist
{
    /** @param list<string> $envios datas (Y-m-d) das cobranças anteriores, da mais antiga para a mais nova */
    public function __construct(
        public int $itemId,
        public string $titulo,
        public \DateTimeImmutable $desde,
        public array $envios,
        public ?\DateTimeImmutable $previsao,
        public int $quebras = 0,
    ) {
    }
}
