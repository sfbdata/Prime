<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Voto\Exception\LimiteDeEnviosDoComprovanteException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;

/** Janela móvel exata. A unidade compartilha a cota entre pautas, endereços e links novos. */
final class LimiteDosEnviosDoComprovante
{
    public function __construct(
        #[Autowire(service: 'cache.rate_limiter')] private readonly CacheItemPoolInterface $cache,
        #[Autowire(service: 'lock.voto_comprovante.factory')] private readonly LockFactory $travas,
        private readonly ClockInterface $relogio,
    ) {
    }

    public function consumir(int $unidade): void
    {
        $chave = 'voto_comprovante_email.' . $unidade;
        $trava = $this->travas->createLock($chave);
        if (!$trava->acquire(true)) {
            throw new \RuntimeException('Não foi possível reservar o envio do comprovante.');
        }
        try {
            $this->registrarTentativa($chave);
        } finally {
            $trava->release();
        }
    }

    private function registrarTentativa(string $chave): void
    {
        $agora = $this->relogio->now()->getTimestamp();
        $item = $this->cache->getItem($chave);
        /** @var list<int> $anteriores */
        $anteriores = $item->isHit() ? $item->get() : [];
        $recentes = array_values(array_filter($anteriores, static fn (int $hora): bool => $hora > $agora - 3600));
        if (\count($recentes) >= 3) {
            throw new LimiteDeEnviosDoComprovanteException();
        }
        $recentes[] = $agora;
        if (!$this->cache->save($item->set($recentes)->expiresAfter(3600))) {
            throw new \RuntimeException('Não foi possível reservar o envio do comprovante.');
        }
    }
}
