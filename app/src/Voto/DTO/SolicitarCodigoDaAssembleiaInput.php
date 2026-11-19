<?php

declare(strict_types=1);

namespace App\Voto\DTO;

use App\Shared\Service\DigitoVerificador;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Identificação do eleitor no portal. Sem `cpf` (assembleia sem CPF na lista), o cadastro é achado por nome, unidade e
 * bloco, como sempre foi. Com `cpf` (só os 11 dígitos, válidos), por nome e CPF: a unidade e o bloco não são pedidos.
 */
final readonly class SolicitarCodigoDaAssembleiaInput
{
    public function __construct(
        #[Assert\NotBlank] #[Assert\Length(max: 160)] public string $nome,
        #[Assert\Length(max: 80)] public string $unidade,
        #[Assert\Length(max: 40)] public string $bloco,
        #[Assert\Email] #[Assert\NotBlank] #[Assert\Length(max: 180)] public string $email,
        #[Assert\Regex('/^[a-f0-9]{32}$/')] public string $desafio,
        public ?string $cpf = null,
    ) {
    }

    public function porCpf(): bool
    {
        return null !== $this->cpf;
    }

    #[Assert\Callback]
    public function validarIdentificacao(ExecutionContextInterface $contexto): void
    {
        if ($this->porCpf()) {
            if (!DigitoVerificador::cpfValido($this->cpf) || 1 !== preg_match('/^\d{11}$/', (string) $this->cpf)) {
                $contexto->buildViolation('CPF inválido.')->atPath('cpf')->addViolation();
            }

            return;
        }
        if ('' === trim($this->unidade)) {
            $contexto->buildViolation('Informe a unidade.')->atPath('unidade')->addViolation();
        }
    }
}
