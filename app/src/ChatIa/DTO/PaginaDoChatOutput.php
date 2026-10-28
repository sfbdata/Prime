<?php

declare(strict_types=1);

namespace App\ChatIa\DTO;

/**
 * O que a página `/chat-ia` precisa saber: se a I.A está conectada (`semChave`), um aviso quando ela está conectada mas
 * não disponível para a pessoa (escritório sem liberação, cota), o primeiro nome para a saudação e a conversa aberta.
 */
final readonly class PaginaDoChatOutput
{
    public function __construct(
        public bool $semChave,
        public ?string $aviso,
        public string $nome,
        public ?int $conversaId,
    ) {
    }
}
