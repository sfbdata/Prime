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
    /**
     * @param list<DestinatarioDaCampanha> $destinatarios os que recebem (já filtrados)
     * @param int|null                     $base          M do dc (L663): a lista antes do filtro; null = igual ao total
     * @param int                          $semInformacao K do dc: ficaram de fora por falta do dado no cadastro
     */
    public function __construct(
        public readonly array $destinatarios,
        public readonly int $descadastrados,
        public readonly int $semAutorizacao,
        private readonly ?int $base = null,
        public readonly int $semInformacao = 0,
    ) {
    }

    public function base(): int
    {
        return $this->base ?? $this->total();
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
