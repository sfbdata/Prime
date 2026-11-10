<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service\ConferenciaDePdf;

/**
 * Interna do motor: roda uma chamada de `openssl` ou `zlib` sem deixar aviso do PHP escapar (dado ruim vem de fora e
 * o retorno `false` já diz o que aconteceu) e esvazia a fila de erros do OpenSSL depois.
 */
final class SemAvisos
{
    private function __construct()
    {
    }

    /**
     * @template T
     *
     * @param callable(): T $acao
     *
     * @return T
     */
    public static function executar(callable $acao): mixed
    {
        set_error_handler(static fn (): bool => true);
        try {
            return $acao();
        } finally {
            restore_error_handler();
            while (openssl_error_string() !== false) {
            }
        }
    }
}
