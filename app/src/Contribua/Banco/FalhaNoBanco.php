<?php

declare(strict_types=1);

namespace App\Contribua\Banco;

/**
 * O banco não respondeu ou recusou o pedido. Quem chama cai no PIX estático ou tenta de novo depois.
 * `passageira`: rede, 5xx ou token recusado (vale pausar tudo e tentar depois); senão o banco recusou ESTE
 * pedido (dado da conta), e repetir não adianta.
 */
final class FalhaNoBanco extends \RuntimeException
{
    public function __construct(string $mensagem, int $codigo = 0, ?\Throwable $anterior = null, public readonly bool $passageira = true)
    {
        parent::__construct($mensagem, $codigo, $anterior);
    }
}
