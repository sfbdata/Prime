<?php

declare(strict_types=1);

namespace App\Tests\Auth\Doubles;

use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\RateLimiter\Reservation;

/**
 * Rate limiter de teste que aceita sempre e **registra as chaves consumidas**.
 *
 * Existe porque o 429 **não é observável por HTTP** no ambiente de teste: o pool
 * `cache.rate_limiter` usa `ArrayAdapter`, que implementa `ResetInterface` e é zerado
 * pelo `services_resetter` ao fim de cada requisição — de propósito, para um teste não
 * herdar a cota gasta pelo outro.
 *
 * O que precisa de prova não é o número 429 (esse é do Symfony e já é exercido em dev),
 * e sim **quando** a cota é consumida: um POST inválido, sem CSRF e sem e-mail, não pode
 * gastar a cota de todo mundo. Este dublê torna esse momento observável.
 *
 * Não delega ao limiter real de propósito — obter o serviço real para embrulhar já o
 * inicializa, e aí o container recusa a substituição. E também não implementa
 * `ResetInterface`: precisa sobreviver ao fim da requisição.
 */
final class RateLimiterFactoryEspiao implements RateLimiterFactoryInterface
{
    /** @var string[] chaves passadas a create(), na ordem */
    public array $chavesConsumidas = [];

    /** @param bool $aceita false = simula cota estourada, para exercitar o 429 */
    public function __construct(
        private readonly bool $aceita = true,
    ) {
    }

    public function create(?string $key = null): LimiterInterface
    {
        $this->chavesConsumidas[] = (string) $key;

        return new class($this->aceita) implements LimiterInterface {
            public function __construct(private readonly bool $aceita)
            {
            }

            public function reserve(int $tokens = 1, ?float $maxTime = null): Reservation
            {
                return new Reservation(0, $this->limite());
            }

            public function consume(int $tokens = 1): RateLimit
            {
                return $this->limite();
            }

            public function reset(): void
            {
            }

            private function limite(): RateLimit
            {
                return $this->aceita
                    ? new RateLimit(PHP_INT_MAX, new \DateTimeImmutable('@0'), true, PHP_INT_MAX)
                    : new RateLimit(0, new \DateTimeImmutable('@0'), false, PHP_INT_MAX);
            }
        };
    }
}
