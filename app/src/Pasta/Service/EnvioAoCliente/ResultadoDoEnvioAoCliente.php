<?php

declare(strict_types=1);

namespace App\Pasta\Service\EnvioAoCliente;

/**
 * Espelho do `ResultadoDoEnvio` do Carteiro. O Carteiro devolve o id do registro e `enfileirado` ou
 * `bloqueado_descadastro`; a implementação provisória (Mailer síncrono) não tem registro: id nulo e
 * situação {@see self::ENVIADO}.
 */
final class ResultadoDoEnvioAoCliente
{
    public const ENVIADO = 'enviado';
    public const ENFILEIRADO = 'enfileirado';
    public const BLOQUEADO_DESCADASTRO = 'bloqueado_descadastro';

    public function __construct(
        public readonly ?int $envioId,
        public readonly string $situacao,
    ) {
    }

    public function saiu(): bool
    {
        return $this->situacao !== self::BLOQUEADO_DESCADASTRO;
    }
}
