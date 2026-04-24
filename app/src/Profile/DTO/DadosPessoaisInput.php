<?php
declare(strict_types=1);
namespace App\Profile\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final class DadosPessoaisInput
{
    #[Assert\NotBlank(message: 'Nome completo é obrigatório.')]
    #[Assert\Length(max: 255)]
    public ?string $nomeCompleto = null;

    #[Assert\Length(max: 14, maxMessage: 'CPF inválido.')]
    public ?string $cpf = null;

    public ?\DateTimeInterface $dataNascimento = null;

    #[Assert\Length(max: 20)]
    public ?string $ctps = null;

    #[Assert\Length(max: 20)]
    public ?string $serie = null;
}
