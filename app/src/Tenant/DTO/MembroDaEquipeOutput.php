<?php

declare(strict_types=1);

namespace App\Tenant\DTO;

use App\Entity\Auth\UserTenant;
use App\Tenant\Enum\SituacaoNaEquipe;

/** Uma linha de "Equipe da conta" (desenho bj-contas.js, aba `conta`). `temAcoes` = o menu oferece alguma ação. */
final readonly class MembroDaEquipeOutput
{
    public function __construct(
        public int $id,
        public string $nome,
        public string $iniciais,
        public string $email,
        public ?string $cargo,
        public bool $primario,
        public bool $master,
        public SituacaoNaEquipe $situacao,
        public bool $temAcoes,
    ) {
    }

    public static function fromEntity(UserTenant $vinculo, bool $master, bool $ehOAtor): self
    {
        $user     = $vinculo->getUser();
        $nome     = (string) $user->getFullName();
        $primario = $vinculo->getTenantRole()?->isSystem() === true;
        $partes   = array_values(array_filter(explode(' ', trim($nome))));
        $iniciais = mb_strtoupper(implode('', array_map(static fn (string $p): string => mb_substr($p, 0, 1), array_slice($partes, 0, 2))));

        return new self(
            (int) $vinculo->getId(),
            $nome,
            $iniciais === '' ? '?' : $iniciais,
            (string) $user->getEmail(),
            $vinculo->getCargo()?->getNome(),
            $primario,
            $master,
            $vinculo->getSituacao(),
            !$primario && !$master && !$ehOAtor,
        );
    }
}
