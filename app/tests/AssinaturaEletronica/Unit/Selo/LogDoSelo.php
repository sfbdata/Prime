<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Unit\Selo;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/** O log dos testes do selo: guarda cada registro, para contar os erros e procurar o que não pode vazar. */
final class LogDoSelo extends AbstractLogger
{
    /** @var list<array{nivel: string, mensagem: string, contexto: array<mixed>}> */
    public array $registros = [];

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->registros[] = ['nivel' => (string) $level, 'mensagem' => (string) $message, 'contexto' => $context];
    }

    /** @return list<string> as mensagens de nível `error` */
    public function erros(): array
    {
        return array_values(array_map(
            static fn (array $r): string => $r['mensagem'],
            array_filter($this->registros, static fn (array $r): bool => $r['nivel'] === LogLevel::ERROR),
        ));
    }

    /** Tudo o que foi registrado, mensagens e contexto, num texto só. */
    public function tudo(): string
    {
        return json_encode($this->registros, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
