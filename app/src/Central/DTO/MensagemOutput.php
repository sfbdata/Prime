<?php

declare(strict_types=1);

namespace App\Central\DTO;

use App\Central\Entity\Mensagem;
use App\Entity\Auth\User;

/**
 * Uma mensagem como a tela a recebe. `minha` é do ponto de vista de quem pediu. `conteudo` vem com as menções no
 * token `@[Nome](user:ID)` — a tela destaca; `respostaA` é a citação (A2), ou `null`.
 */
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
        public readonly ?RespostaOutput $respostaA,
        public readonly bool $sistema,
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
            respostaA: $mensagem->getRespostaA() !== null ? RespostaOutput::fromEntity($mensagem->getRespostaA(), $eu) : null,
            sistema: $mensagem->isSistema(),
        );
    }
}
