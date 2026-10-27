<?php

declare(strict_types=1);

namespace App\Dashboard\DTO;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;

/** Marca na análise de uma pessoa: escritório e usuário vêm da sessão, nunca do corpo. */
final readonly class MarcarPessoaDaInteligenciaInput
{
    public function __construct(
        public Tenant $tenant,
        public User $usuario,
        public int $pessoaId,
        /** analisado | sim | nao | parcial (MarcasDasPessoas::acoes()). */
        public string $acao,
    ) {
    }
}
