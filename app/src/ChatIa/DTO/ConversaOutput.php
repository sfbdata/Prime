<?php

declare(strict_types=1);

namespace App\ChatIa\DTO;

/** A conversa aberta: `{id, titulo, modelo, fixada, mensagens: [...]}`. */
final readonly class ConversaOutput
{
    /** @param list<MensagemOutput> $mensagens */
    public function __construct(
        public int $id,
        public ?string $titulo,
        public string $modelo,
        public bool $fixada,
        public array $mensagens,
    ) {
    }

    /** @return array<string, mixed> */
    public function paraArray(): array
    {
        return [
            'id' => $this->id,
            'titulo' => $this->titulo,
            'modelo' => $this->modelo,
            'fixada' => $this->fixada,
            'mensagens' => array_map(static fn (MensagemOutput $m): array => $m->paraArray(), $this->mensagens),
        ];
    }
}
