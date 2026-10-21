<?php

declare(strict_types=1);

namespace App\Master\DTO;

/** A mensagem aberta na Central do Master: a linha (já lida) e o histórico de respostas de todos os Masters. */
final class MensagemAbertaOutput
{
    /** @param list<RespostaDaCentralOutput> $historico */
    public function __construct(
        public readonly ItemDaCentral $item,
        public readonly array $historico,
    ) {
    }

    /** @return array<string, mixed> */
    public function emJson(): array
    {
        return [
            'mensagem' => $this->item->emJson(),
            'historico' => array_map(static fn (RespostaDaCentralOutput $r): array => $r->emJson(), $this->historico),
        ];
    }
}
