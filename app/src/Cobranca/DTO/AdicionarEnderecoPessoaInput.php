<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Entrada para adicionar um novo endereço à lista de uma Pessoa (spec de qualificação §4). Será a
 * data_class do respectivo Type (Onda de UI). A resolução da pessoa por id (guarda multi-tenant), a
 * regra de "primeiro item nasce atual" e a persistência ocorrem no AdicionarEnderecoPessoaUseCase.
 */
final class AdicionarEnderecoPessoaInput
{
    #[Assert\NotNull(message: 'Informe a pessoa.')]
    #[Assert\Positive(message: 'Pessoa inválida.')]
    public ?int $pessoaId = null;

    #[Assert\NotBlank(message: 'Informe o logradouro.')]
    #[Assert\Length(max: 255, maxMessage: 'O logradouro pode ter no máximo {{ limit }} caracteres.')]
    public ?string $logradouro = null;

    #[Assert\NotBlank(message: 'Informe o número.')]
    #[Assert\Length(max: 20, maxMessage: 'O número pode ter no máximo {{ limit }} caracteres.')]
    public ?string $numero = null;

    #[Assert\Length(max: 120, maxMessage: 'O complemento pode ter no máximo {{ limit }} caracteres.')]
    public ?string $complemento = null;

    #[Assert\NotBlank(message: 'Informe o bairro.')]
    #[Assert\Length(max: 120, maxMessage: 'O bairro pode ter no máximo {{ limit }} caracteres.')]
    public ?string $bairro = null;

    #[Assert\NotBlank(message: 'Informe a cidade.')]
    #[Assert\Length(max: 120, maxMessage: 'A cidade pode ter no máximo {{ limit }} caracteres.')]
    public ?string $cidade = null;

    #[Assert\NotBlank(message: 'Informe a UF.')]
    #[Assert\Length(exactly: 2, exactMessage: 'A UF deve ter {{ limit }} caracteres.')]
    public ?string $uf = null;

    #[Assert\NotBlank(message: 'Informe o CEP.')]
    #[Assert\Length(max: 9, maxMessage: 'O CEP pode ter no máximo {{ limit }} caracteres.')]
    public ?string $cep = null;
}
