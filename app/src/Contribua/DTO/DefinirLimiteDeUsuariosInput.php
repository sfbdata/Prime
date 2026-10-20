<?php

declare(strict_types=1);

namespace App\Contribua\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/** "Definir limite de usuários" do Master (desenho 05). Nulo = volta a valer o do plano. */
final readonly class DefinirLimiteDeUsuariosInput
{
    public function __construct(
        #[Assert\Positive]
        public int $tenantId,
        #[Assert\Positive(message: 'Informe um limite de usuários maior que zero.')]
        public ?int $limite,
        #[Assert\NotBlank(message: 'A justificativa é obrigatória e fica na auditoria.')]
        public string $justificativa,
    ) {
    }
}
