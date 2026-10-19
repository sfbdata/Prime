<?php

declare(strict_types=1);

namespace App\Master\DTO;

use App\Entity\Auth\User;

/** Excluir conta pelo painel do Master (spec perfis-1.2-contas C4): exige a palavra EXCLUIR. */
final readonly class ExcluirContaInput
{
    public function __construct(
        /** Quem confirma no painel: um Master. */
        public User $ator,
        /** Id do escritório (rota /master/contas/{id}/excluir). */
        public int $contaId,
        /** O que foi digitado no campo "Digite EXCLUIR para confirmar". */
        public string $confirmacao,
    ) {
    }
}
