<?php

declare(strict_types=1);

namespace App\ChatIa\DTO;

/** Uma pasta que a pessoa pode citar: `{id, nup, cliente}`. */
final readonly class PastaCitavelOutput
{
    public function __construct(
        public int $id,
        public string $nup,
        public ?string $cliente,
    ) {
    }

    /** @return array{id: int, nup: string, cliente: ?string} */
    public function paraArray(): array
    {
        return ['id' => $this->id, 'nup' => $this->nup, 'cliente' => $this->cliente];
    }
}
