<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\Pdf;

use Psr\Log\AbstractLogger;

/** Guarda o que foi para o log, para o teste conferir o nível, a mensagem e o contexto. */
final class AvisosDeTeste extends AbstractLogger
{
    /** @var list<array{string, string, array<mixed>}> */
    public array $registros = [];

    /** @param array<mixed> $context */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->registros[] = [(string) $level, (string) $message, $context];
    }
}
