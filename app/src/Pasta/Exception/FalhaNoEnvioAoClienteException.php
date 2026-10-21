<?php

declare(strict_types=1);

namespace App\Pasta\Exception;

/**
 * O e-mail não saiu para algum destinatário (falha do transporte). `enviados` são os que já saíram
 * (gravados no histórico): a tela os tira da seleção para o "tente de novo" não repetir o envio.
 */
final class FalhaNoEnvioAoClienteException extends \RuntimeException
{
    public const MENSAGEM = 'Falha no envio. O texto foi mantido; tente de novo.';

    /** @param list<string> $enviados */
    public function __construct(public readonly array $enviados, ?\Throwable $anterior = null)
    {
        parent::__construct(self::MENSAGEM, 0, $anterior);
    }
}
