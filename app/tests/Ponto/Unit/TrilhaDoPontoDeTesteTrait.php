<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Ponto\Repository\TrilhaDoPontoRepository;
use App\Ponto\Service\ChavesDoHmacDoPonto;
use App\Ponto\Service\TrilhaDoPonto;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;

/**
 * A trilha de auditoria do Ponto (E9) de verdade, sobre um repositório falso, para os testes unitários dos UseCases que
 * gravam eventos: `emTransacao` roda o trabalho na hora (sem banco) e cada linha inserida fica em `$eventosDaTrilha`.
 */
trait TrilhaDoPontoDeTesteTrait
{
    /** @var list<array<string, mixed>> */
    private array $eventosDaTrilha = [];

    private function trilhaDeTeste(?string $segredo = null): TrilhaDoPonto
    {
        $repositorio = $this->createStub(TrilhaDoPontoRepository::class);
        $repositorio->method('emTransacao')->willReturnCallback(static fn (\Closure $trabalho): mixed => $trabalho());
        $repositorio->method('ultimoElo')->willReturnCallback(function (): ?array {
            $ultimo = $this->eventosDaTrilha[\count($this->eventosDaTrilha) - 1] ?? null;

            // Como o repositório: o último selo da cadeia, mesmo quando a última linha entrou sem selo.
            return $ultimo === null ? null : ['sequencia' => $ultimo['sequencia'], 'selo' => $ultimo['selo'] ?? $ultimo['selo_anterior']];
        });
        $repositorio->method('inserir')->willReturnCallback(function (array $linha): void {
            $this->eventosDaTrilha[] = $linha;
        });

        return new TrilhaDoPonto($repositorio, new ChavesDoHmacDoPonto($segredo), new MockClock('2026-03-10 08:00:00'), new TokenStorage());
    }
}
