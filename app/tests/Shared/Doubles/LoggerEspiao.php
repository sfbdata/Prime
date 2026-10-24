<?php

declare(strict_types=1);

namespace App\Tests\Shared\Doubles;

use Psr\Log\AbstractLogger;

/** Guarda o que foi logado, para provar o que NÃO pode aparecer (nome de arquivo, chave remota, segredo). */
final class LoggerEspiao extends AbstractLogger
{
    /** @var list<array{nivel: string, mensagem: string}> */
    public array $registros = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->registros[] = ['nivel' => (string) $level, 'mensagem' => (string) $message . ' ' . json_encode($context, \JSON_UNESCAPED_UNICODE)];
    }
}
