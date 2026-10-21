<?php

declare(strict_types=1);

namespace App\Comercial\Envio;

/**
 * O que o Carteiro fez com a mensagem e o id do registro em `comercial_envio`, para o consumidor ligar ao histórico
 * dele e consultar a situação depois ({@see RegistroDeEnviosInterface}).
 */
final class ResultadoDoEnvio
{
    public const ENFILEIRADO = 'enfileirado';
    public const BLOQUEADO_DESCADASTRO = 'bloqueado_descadastro';

    public function __construct(
        public readonly int $envioId,
        public readonly string $situacao,
    ) {
    }

    public function foiEnfileirado(): bool
    {
        return $this->situacao === self::ENFILEIRADO;
    }
}
