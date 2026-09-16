<?php

declare(strict_types=1);

namespace App\Tests\Shared\Doubles;

use Psr\Log\AbstractLogger;

/**
 * Guarda os registros para o teste conferir — depois do COMMIT, o log é o único rastro de um
 * arquivo que não pôde ser removido (E2.5).
 */
final class LoggerEmMemoria extends AbstractLogger
{
    /** @var list<array{nivel: string, mensagem: string, contexto: array<mixed>}> */
    public array $registros = [];

    /** Registra e LANÇA — o handler que perdeu o destino. Registrar não pode mudar o desfecho. */
    public bool $falhar = false;

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->registros[] = ['nivel' => (string) $level, 'mensagem' => (string) $message, 'contexto' => $context];

        if ($this->falhar) {
            throw new \UnexpectedValueException('logger sem destino gravável (dublê)');
        }
    }

    /** @return list<array{nivel: string, mensagem: string, contexto: array<mixed>}> */
    public function doNivel(string $nivel): array
    {
        return array_values(array_filter(
            $this->registros,
            static fn (array $r): bool => $r['nivel'] === $nivel,
        ));
    }
}
