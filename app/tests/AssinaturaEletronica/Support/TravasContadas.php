<?php

declare(strict_types=1);

namespace App\Tests\AssinaturaEletronica\Support;

use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Loja de travas em memória que conta as renovações (`putOffExpiration`) de cada trava, pelo nome: torna observável a
 * renovação das travas da geração do PDF a cada pedaço. O `acquire()` com TTL também renova uma vez. Com
 * {@see $falhaAoRenovar}, a renovação daquela trava falha como a trava perdida para outro processo.
 */
final class TravasContadas implements PersistingStoreInterface
{
    /** @var array<string, int> renovações por nome de trava */
    public array $renovacoes = [];

    public ?string $falhaAoRenovar = null;

    private readonly InMemoryStore $memoria;

    public function __construct()
    {
        $this->memoria = new InMemoryStore();
    }

    public function save(Key $key): void
    {
        $this->memoria->save($key);
    }

    public function delete(Key $key): void
    {
        $this->memoria->delete($key);
    }

    public function exists(Key $key): bool
    {
        return $this->memoria->exists($key);
    }

    public function putOffExpiration(Key $key, float $ttl): void
    {
        $this->renovacoes[(string) $key] = ($this->renovacoes[(string) $key] ?? 0) + 1;
        if ((string) $key === $this->falhaAoRenovar) {
            throw new LockConflictedException();
        }
        $this->memoria->putOffExpiration($key, $ttl);
    }

    public function renovacoesDe(string $trava): int
    {
        return $this->renovacoes[$trava] ?? 0;
    }
}
