<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Entity\Pessoa;

/**
 * Leitura da ficha completa da Pessoa cobrada (spec de qualificação §3/§4/§7): os campos ÚNICOS de
 * qualificação (nome/cpf/cnpj/observacao/dataNascimento/estadoCivil/profissao/rg/orgaoEmissorRg) mais
 * as três LISTAS (endereços/telefones/e-mails), cada uma já ordenada `criadoEm ASC` pelo repositório
 * (`listarPorPessoa`). Montado por `MontarFichaPessoaUseCase` (leitura pura); o controller não calcula
 * nada. `PessoaController::show` é a única tela que expõe a ficha inteira.
 *
 * @param list<PessoaEnderecoItemOutput> $enderecos
 * @param list<PessoaTelefoneItemOutput> $telefones
 * @param list<PessoaEmailItemOutput>    $emails
 */
final class PessoaFichaOutput
{
    public function __construct(
        public readonly int $id,
        public readonly string $nome,
        public readonly ?string $cpf,
        public readonly ?string $cnpj,
        public readonly ?string $observacao,
        public readonly ?\DateTimeImmutable $dataNascimento,
        public readonly ?string $estadoCivilValue,
        public readonly ?string $estadoCivilLabel,
        public readonly ?string $profissao,
        public readonly ?string $rg,
        public readonly ?string $orgaoEmissorRg,
        public readonly \DateTimeImmutable $criadoEm,
        public readonly array $enderecos,
        public readonly array $telefones,
        public readonly array $emails,
    ) {
    }

    /**
     * @param list<PessoaEnderecoItemOutput> $enderecos
     * @param list<PessoaTelefoneItemOutput> $telefones
     * @param list<PessoaEmailItemOutput>    $emails
     */
    public static function fromEntity(Pessoa $pessoa, array $enderecos, array $telefones, array $emails): self
    {
        return new self(
            id: $pessoa->getId() ?? 0,
            nome: $pessoa->getNome(),
            cpf: $pessoa->getCpf(),
            cnpj: $pessoa->getCnpj(),
            observacao: $pessoa->getObservacao(),
            dataNascimento: $pessoa->getDataNascimento(),
            estadoCivilValue: $pessoa->getEstadoCivil()?->value,
            estadoCivilLabel: $pessoa->getEstadoCivil()?->rotulo(),
            profissao: $pessoa->getProfissao(),
            rg: $pessoa->getRg(),
            orgaoEmissorRg: $pessoa->getOrgaoEmissorRg(),
            criadoEm: $pessoa->getCriadoEm(),
            enderecos: $enderecos,
            telefones: $telefones,
            emails: $emails,
        );
    }
}
