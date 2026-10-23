<?php

declare(strict_types=1);

namespace App\Master\Notificacoes;

use App\Master\DTO\MensagemDaCentral;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Todas as origens da Central do Master, pela chave gravada em `origem`. */
final class OrigensDaCentral
{
    /** @var array<string, OrigemDaCentral> */
    private array $porChave = [];

    /** @param iterable<OrigemDaCentral> $origens */
    public function __construct(
        #[AutowireIterator(OrigemDaCentral::TAG)]
        iterable $origens,
    ) {
        foreach ($origens as $origem) {
            $this->porChave[$origem->origem()] = $origem;
        }
    }

    public function da(string $origem): ?OrigemDaCentral
    {
        return $this->porChave[$origem] ?? null;
    }

    public function mensagem(string $origem, int $idOrigem): ?MensagemDaCentral
    {
        return $this->da($origem)?->uma($idOrigem);
    }

    /** @return list<MensagemDaCentral> de todas as origens, as mais novas primeiro */
    public function todas(): array
    {
        $todas = [];
        foreach ($this->porChave as $origem) {
            array_push($todas, ...$origem->todas());
        }
        usort($todas, static fn (MensagemDaCentral $a, MensagemDaCentral $b): int => [$b->em, $b->origem, $b->idOrigem] <=> [$a->em, $a->origem, $a->idOrigem]);

        return $todas;
    }
}
