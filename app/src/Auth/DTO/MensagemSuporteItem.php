<?php

declare(strict_types=1);

namespace App\Auth\DTO;

use App\Auth\Entity\MensagemSuporte;

/** Uma linha da lista /suporte/mensagens. */
final class MensagemSuporteItem
{
    public function __construct(
        public readonly \DateTimeImmutable $criadaEm,
        public readonly string $nome,
        public readonly string $email,
        public readonly ?string $assunto,
        public readonly string $mensagem,
        public readonly string $origem,
    ) {
    }

    public static function fromEntity(MensagemSuporte $mensagem): self
    {
        return new self(
            criadaEm: $mensagem->getCriadaEm(),
            nome: $mensagem->getNome(),
            email: $mensagem->getEmail(),
            assunto: $mensagem->getAssunto(),
            mensagem: $mensagem->getMensagem(),
            origem: $mensagem->getOrigem(),
        );
    }
}
