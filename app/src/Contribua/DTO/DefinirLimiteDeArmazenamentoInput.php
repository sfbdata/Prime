<?php

declare(strict_types=1);

namespace App\Contribua\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/** "Editar limite de armazenamento" do Master (desenho 05), mais o "sem limite" (convenção). */
final readonly class DefinirLimiteDeArmazenamentoInput
{
    public function __construct(
        #[Assert\Positive]
        public int $tenantId,
        #[Assert\Positive(message: 'Informe um limite em GB maior que zero.')]
        public ?int $cotaGb,
        public bool $semLimite,
        #[Assert\NotBlank(message: 'A justificativa é obrigatória e fica na auditoria.')]
        public string $justificativa,
    ) {
    }
}
