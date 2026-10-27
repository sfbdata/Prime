<?php

declare(strict_types=1);

namespace App\Dashboard\DTO;

/** A marca do usuário na análise de uma pessoa, depois da gravação (o que a tela deve mostrar). */
final readonly class MarcaDaPessoaOutput
{
    public function __construct(
        public int $pessoaId,
        public ?string $status,
        public ?string $feedback,
    ) {
    }

    /** @return array{pessoa: int, status: ?string, feedback: ?string} */
    public function paraArray(): array
    {
        return ['pessoa' => $this->pessoaId, 'status' => $this->status, 'feedback' => $this->feedback];
    }
}
