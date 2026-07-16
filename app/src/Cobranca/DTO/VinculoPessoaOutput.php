<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Entity\VinculoPessoaObjeto;

/**
 * Leitura de um vínculo Pessoa↔Objeto para o card da pessoa da página unificada do objeto (ajuste 2;
 * a partir do ajuste 10, o card substitui a antiga aba "Pessoas" — que era a única a abrir por padrão).
 * `ehCobradaAtual` marca o vínculo cuja pessoa é a `pessoaCobradaAtual` do caso âncora — assim a UI
 * destaca quem está sendo cobrado sem precisar recomputar nada. Vínculos encerrados (histórico,
 * invariável 11) entram na lista com `ativo = false`.
 */
final class VinculoPessoaOutput
{
    public function __construct(
        public readonly int $vinculoId,
        public readonly int $pessoaId,
        public readonly string $nome,
        public readonly ?string $cpf,
        public readonly ?string $cnpj,
        public readonly ?string $email,
        public readonly ?string $telefone,
        public readonly string $papelLabel,
        public readonly \DateTimeImmutable $dataInicio,
        public readonly ?\DateTimeImmutable $dataFim,
        public readonly bool $ativo,
        public readonly bool $ehCobradaAtual,
        public readonly ?string $motivoEncerramento = null,
    ) {
    }

    public static function fromEntity(VinculoPessoaObjeto $vinculo, ?int $pessoaCobradaAtualId): self
    {
        $pessoa = $vinculo->getPessoa();
        $pessoaId = $pessoa?->getId();

        return new self(
            vinculoId: $vinculo->getId() ?? 0,
            pessoaId: $pessoaId ?? 0,
            nome: $pessoa?->getNome() ?? '—',
            cpf: $pessoa?->getCpf(),
            cnpj: $pessoa?->getCnpj(),
            email: $pessoa?->getEmail(),
            telefone: $pessoa?->getTelefone(),
            papelLabel: $vinculo->getTipoVinculo()->label(),
            dataInicio: $vinculo->getDataInicio(),
            dataFim: $vinculo->getDataFim(),
            ativo: $vinculo->estaAberto(),
            ehCobradaAtual: $pessoaCobradaAtualId !== null && $pessoaId === $pessoaCobradaAtualId,
            motivoEncerramento: $vinculo->getMotivoEncerramento(),
        );
    }
}
