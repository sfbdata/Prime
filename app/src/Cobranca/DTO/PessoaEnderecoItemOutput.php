<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Entity\PessoaEndereco;

/**
 * Leitura de um item da lista de endereços da Pessoa (spec de qualificação §4/§7), para a ficha
 * (`PessoaController::show`). Read-only: a mutação (adicionar/marcar atual) passa pelos UseCases
 * já existentes (Adicionar/MarcarEnderecoAtual), não por este DTO.
 */
final class PessoaEnderecoItemOutput
{
    public function __construct(
        public readonly int $id,
        public readonly string $logradouro,
        public readonly string $numero,
        public readonly ?string $complemento,
        public readonly string $bairro,
        public readonly string $cidade,
        public readonly string $uf,
        public readonly string $cep,
        public readonly bool $atual,
        public readonly \DateTimeImmutable $criadoEm,
    ) {
    }

    public static function fromEntity(PessoaEndereco $endereco): self
    {
        return new self(
            id: $endereco->getId() ?? 0,
            logradouro: $endereco->getLogradouro(),
            numero: $endereco->getNumero(),
            complemento: $endereco->getComplemento(),
            bairro: $endereco->getBairro(),
            cidade: $endereco->getCidade(),
            uf: $endereco->getUf(),
            cep: $endereco->getCep(),
            atual: $endereco->isAtual(),
            criadoEm: $endereco->getCriadoEm(),
        );
    }
}
