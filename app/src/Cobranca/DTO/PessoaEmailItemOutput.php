<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Entity\PessoaEmail;

/**
 * Leitura de um item da lista de e-mails da Pessoa (spec de qualificação §4/§7), para a ficha
 * (`PessoaController::show`). Read-only: a mutação (adicionar/marcar atual) passa pelos UseCases
 * já existentes (Adicionar/MarcarEmailAtual), não por este DTO.
 */
final class PessoaEmailItemOutput
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly bool $atual,
        public readonly \DateTimeImmutable $criadoEm,
    ) {
    }

    public static function fromEntity(PessoaEmail $email): self
    {
        return new self(
            id: $email->getId() ?? 0,
            email: $email->getEmail(),
            atual: $email->isAtual(),
            criadoEm: $email->getCriadoEm(),
        );
    }
}
