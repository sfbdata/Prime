<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Enum\EstadoCivil;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada da edição da qualificação da Pessoa (spec de qualificação §3/§7): SOMENTE os campos
 * próprios da Pessoa (`nome`, `cpf`, `cnpj`, `observacao`, `dataNascimento`, `estadoCivil`,
 * `profissao`, `rg`, `orgaoEmissorRg`). NUNCA `email`/`telefone` — esses são geridos pelas listas
 * (Adicionar/MarcarAtual); editar diretamente sobrescreveria o histórico via o bridge
 * `setEmail()`/`setTelefone()` (SPEC §6). `pessoaId` vem da rota. A resolução por id + tenant
 * (guarda multi-tenant) e a normalização final (trim; null se vazio) ocorrem no
 * `EditarQualificacaoPessoaUseCase`.
 */
final class EditarQualificacaoPessoaInput
{
    #[Assert\NotNull(message: 'Informe a pessoa.')]
    #[Assert\Positive(message: 'Pessoa inválida.')]
    public ?int $pessoaId = null;

    #[Assert\NotBlank(message: 'Informe o nome da pessoa.')]
    #[Assert\Length(max: 255, maxMessage: 'O nome pode ter no máximo {{ limit }} caracteres.')]
    public ?string $nome = null;

    #[Assert\Length(max: 14, maxMessage: 'O CPF pode ter no máximo {{ limit }} caracteres.')]
    public ?string $cpf = null;

    #[Assert\Length(max: 18, maxMessage: 'O CNPJ pode ter no máximo {{ limit }} caracteres.')]
    public ?string $cnpj = null;

    public ?string $observacao = null;

    public ?\DateTimeImmutable $dataNascimento = null;

    public ?EstadoCivil $estadoCivil = null;

    #[Assert\Length(max: 120, maxMessage: 'A profissão pode ter no máximo {{ limit }} caracteres.')]
    public ?string $profissao = null;

    #[Assert\Length(max: 20, maxMessage: 'O RG pode ter no máximo {{ limit }} caracteres.')]
    public ?string $rg = null;

    #[Assert\Length(max: 20, maxMessage: 'O órgão emissor pode ter no máximo {{ limit }} caracteres.')]
    public ?string $orgaoEmissorRg = null;
}
