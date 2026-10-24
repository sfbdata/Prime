<?php

declare(strict_types=1);

namespace App\Cliente\DTO;

use App\Cliente\Entity\Cliente;

/** A foto do cliente depois da troca ou da remoção: o nome (null = sem foto) e quando mudou, para a URL `?v=`. */
final class FotoDoClienteOutput
{
    public function __construct(
        public readonly int $clienteId,
        public readonly ?string $nome,
        public readonly ?\DateTimeImmutable $atualizadaEm,
    ) {
    }

    public static function de(Cliente $cliente): self
    {
        return new self((int) $cliente->getId(), $cliente->getFoto(), $cliente->getFotoAtualizadaEm());
    }
}
