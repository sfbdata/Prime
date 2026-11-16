<?php

declare(strict_types=1);

namespace App\Voto\DTO;

use App\Voto\Entity\MembroDaEquipe;

/** Uma linha da equipe própria na tela Perfis. Nunca leva o id do usuário: só o que a tela mostra. */
final readonly class LinhaDaEquipe
{
    public function __construct(
        public int $id,
        public string $nome,
        public string $email,
        public string $cargo,
        public string $rotulo,
        public string $papel,
        public string $papelRotulo,
        public bool $comConta,
    ) {
    }

    public static function de(MembroDaEquipe $membro): self
    {
        return new self(
            (int) $membro->getId(),
            $membro->getNome(),
            $membro->getEmail() ?? '',
            $membro->getCargo(),
            $membro->rotulo(),
            $membro->getPapel()->value,
            $membro->getPapel()->rotulo(),
            null !== $membro->getUser(),
        );
    }
}
