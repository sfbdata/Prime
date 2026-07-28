<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Entity\PessoaTelefone;
use App\Cobranca\Enum\TipoTelefone;

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
        /** Nulo no telefone anterior a 2026-07-28: ninguém declarou o tipo, e o sistema não chuta. */
        public readonly ?TipoTelefone $tipo = null,
    ) {
    }

    public static function fromEntity(PessoaTelefone $telefone): self
    {
        return new self(
            id: $telefone->getId() ?? 0,
            numero: $telefone->getNumero(),
            atual: $telefone->isAtual(),
            criadoEm: $telefone->getCriadoEm(),
            tipo: $telefone->getTipo(),
        );
    }
}
