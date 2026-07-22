<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Entity\PessoaTelefone;

/**
 * Leitura de um item da lista de telefones da Pessoa (spec de qualificação §4/§7), para a ficha
 * (`PessoaController::show`). Read-only: a mutação (adicionar/marcar atual) passa pelos UseCases
 * já existentes (Adicionar/MarcarTelefoneAtual), não por este DTO.
 */
final class PessoaTelefoneItemOutput
{
    public function __construct(
        public readonly int $id,
        public readonly string $numero,
        public readonly bool $atual,
        public readonly \DateTimeImmutable $criadoEm,
    ) {
    }

    public static function fromEntity(PessoaTelefone $telefone): self
    {
        return new self(
            id: $telefone->getId() ?? 0,
            numero: $telefone->getNumero(),
            atual: $telefone->isAtual(),
            criadoEm: $telefone->getCriadoEm(),
        );
    }
}
