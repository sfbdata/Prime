<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Enum\EstadoCivil;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada do cadastro de Pessoa (SPEC §7/§24). É a data_class do formulário de cobranças.
 * CPF e CNPJ são OPCIONAIS por regra de negócio: a ausência não impede o cadastro. A
 * normalização final (trim; null se vazio) ocorre no CriarPessoaUseCase.
 *
 * 2026-07-28: os campos de qualificação (nascimento, estado civil, profissão, RG, órgão emissor)
 * entraram para o cadastro poder nascer COMPLETO — o modal único da aba Responsáveis pergunta tudo de
 * uma vez, em vez de deixar o badge `Qualificação incompleta` cobrar depois. Todos são opcionais e
 * nascem nulos: quem já usava este Input (importador de relatório, criação de objeto com cobrança)
 * segue passando exatamente o que passava.
 */
final class CriarPessoaInput
{
    #[Assert\NotBlank(message: 'Informe o nome da pessoa.')]
    #[Assert\Length(max: 255, maxMessage: 'O nome pode ter no máximo {{ limit }} caracteres.')]
    public ?string $nome = null;

    #[Assert\Length(max: 14, maxMessage: 'O CPF pode ter no máximo {{ limit }} caracteres.')]
    public ?string $cpf = null;

    #[Assert\Length(max: 18, maxMessage: 'O CNPJ pode ter no máximo {{ limit }} caracteres.')]
    public ?string $cnpj = null;

    #[Assert\Email(message: 'Informe um e-mail válido.')]
    public ?string $email = null;

    #[Assert\Length(max: 20, maxMessage: 'O telefone pode ter no máximo {{ limit }} caracteres.')]
    public ?string $telefone = null;

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
