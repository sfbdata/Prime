<?php

declare(strict_types=1);

namespace App\Agenda\DTO;

/** Um lembrete que o navegador mostra no horário (cartão com som, desenho 09 e bj-lembretes.js). */
final readonly class LembreteOutput
{
    public function __construct(
        /** Chave estável: o navegador guarda quais já foram dispensados ou adiados. */
        public string $k,
        public string $titulo,
        /** Início do compromisso, AAAA-MM-DDTHH:MM:SS na hora de parede (sem fuso). */
        public string $quando,
        /** Quando avisar, mesmo formato. */
        public string $aviso,
        /** meus, aud, reun ou prazo (som e cor do cartão). */
        public string $tipo,
        public ?string $observacao,
        public ?int $eventoId,
        public ?int $pastaId,
        public string $dia,
    ) {
    }

    /** @return array<string, string|int|null> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
