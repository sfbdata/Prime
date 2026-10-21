<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

/**
 * O público de uma campanha depois dos filtros (spec §8.5): sem repetidos, sem os descadastrados da lista
 * `comercial_campanhas` (ou de `todos`) e, no CSV e no Individual, só quem tem autorização (B1). Os números dos que
 * saíram vão à linha de destinatários do dc (L.252).
 */
final class PublicoResolvido
{
    /** @param list<DestinatarioDaCampanha> $destinatarios */
    public function __construct(
        public readonly array $destinatarios,
        public readonly int $descadastrados,
        public readonly int $semAutorizacao,
    ) {
    }

    public function total(): int
    {
        return \count($this->destinatarios);
    }

    public function primeiro(): ?DestinatarioDaCampanha
    {
        return $this->destinatarios[0] ?? null;
    }
}
