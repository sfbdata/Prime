<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use App\Cobranca\Enum\TipoVinculo;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada da "Nova pessoa" DENTRO do objeto (ajuste 2, aba Pessoas): cadastra a pessoa e já a vincula
 * ao objeto. `objetoId` NÃO é campo — vem da rota. Só o nome é obrigatório (CPF/CNPJ/e-mail/telefone
 * são opcionais no modelo). A normalização dos textos ocorre no CriarPessoaUseCase.
 */
final class CriarPessoaVinculadaInput
{
    #[Assert\NotBlank(message: 'Informe o nome da pessoa.')]
    #[Assert\Length(max: 255, maxMessage: 'O nome pode ter no máximo {{ limit }} caracteres.')]
    public ?string $nome = null;

    public ?string $cpf = null;

    public ?string $cnpj = null;

    #[Assert\Email(message: 'E-mail inválido.')]
    public ?string $email = null;

    public ?string $telefone = null;

    public TipoVinculo $tipoVinculo = TipoVinculo::Outro;
}
