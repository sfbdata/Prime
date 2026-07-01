<?php

declare(strict_types=1);

namespace App\Auth\DTO;

use App\Auth\Enum\StatusOab;

/**
 * Resultado da verificação de OAB já traduzido para o domínio (o que o ValidadorOab decidiu):
 * o status a gravar no usuário + o nome oficial/situação retornados (para a revisão do admin).
 */
final class ResultadoVerificacaoOab
{
    public function __construct(
        public readonly StatusOab $status,
        public readonly ?string $nomeOficial,
        public readonly ?string $situacao,
    ) {
    }
}
