<?php

declare(strict_types=1);

namespace App\Central\DTO;

use App\Central\Entity\Mensagem;
use App\Entity\Auth\User;

/** Uma mensagem como a tela a recebe. `minha` é do ponto de vista de quem pediu. */
final class MensagemOutput
{
    private function __construct(
        public readonly int $id,
        public readonly int $conversaId,
        public readonly int $autorId,
        public readonly string $autorNome,
        public readonly string $conteudo,
        public readonly string $criadaEm,
        public readonly bool $minha,
    ) {
    }

    public static function fromEntity(Mensagem $mensagem, User $eu): self
    {
        $autor = $mensagem->getAutor();

        return new self(
            id: (int) $mensagem->getId(),
            conversaId: (int) $mensagem->getConversa()->getId(),
            autorId: (int) $autor->getId(),
            autorNome: (string) $autor->getFullName(),
            conteudo: $mensagem->getConteudo(),
            criadaEm: $mensagem->getCriadaEm()->format(\DateTimeInterface::ATOM),
            minha: $autor->getId() === $eu->getId(),
        );
    }
}
