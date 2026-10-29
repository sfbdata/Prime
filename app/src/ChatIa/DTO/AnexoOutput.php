<?php

declare(strict_types=1);

namespace App\ChatIa\DTO;

use App\ChatIa\Entity\AnexoDeChat;

/** `{id, nome, mime, tamanho}` de um anexo (a resposta do envio) ou `{id, nome}` dentro da mensagem. */
final readonly class AnexoOutput
{
    public function __construct(
        public int $id,
        public string $nome,
        public string $mime,
        public int $tamanho,
    ) {
    }

    public static function de(AnexoDeChat $anexo): self
    {
        return new self((int) $anexo->getId(), $anexo->getNome(), $anexo->getMime(), $anexo->getTamanho());
    }

    /** @return array{id: int, nome: string, mime: string, tamanho: int} */
    public function paraArray(): array
    {
        return ['id' => $this->id, 'nome' => $this->nome, 'mime' => $this->mime, 'tamanho' => $this->tamanho];
    }
}
