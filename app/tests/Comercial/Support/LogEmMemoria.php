<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Support;

use Psr\Log\AbstractLogger;

/** Logger de teste: guarda as mensagens e o contexto em texto, para provar que nenhum endereço vaza. */
final class LogEmMemoria extends AbstractLogger
{
    public string $tudo = '';

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->tudo .= $message . ' ' . json_encode($context, JSON_UNESCAPED_UNICODE) . "\n";
    }
}
