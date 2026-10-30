<?php

declare(strict_types=1);

namespace App\ChatIa\DTO;

use App\Cliente\Entity\Cliente;

/**
 * O que a validação de uma proposta achou: os erros por campo (`campos.<chave>` ou `pasta.<campo>`) e o cadastro
 * EXISTENTE do escritório quando o CPF/CNPJ informado já é de alguém (`documento` = 'CPF' ou 'CNPJ').
 */
final readonly class ResultadoDaValidacao
{
    /** @param array<string, string> $erros */
    public function __construct(
        public array $erros = [],
        public ?Cliente $existente = null,
        public ?string $documento = null,
    ) {
    }

    public function valido(): bool
    {
        return $this->erros === [];
    }
}
