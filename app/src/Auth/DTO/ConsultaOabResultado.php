<?php

declare(strict_types=1);

namespace App\Auth\DTO;

/**
 * Retorno cru do web service de OAB (o que o CNA respondeu), já normalizado num objeto.
 * `existe = false` significa que o número+UF não corresponde a nenhum advogado.
 */
final class ConsultaOabResultado
{
    public function __construct(
        public readonly bool $existe,
        public readonly ?string $nomeOficial,
        public readonly ?string $situacao,
    ) {
    }
}
