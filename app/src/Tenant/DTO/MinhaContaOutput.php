<?php

declare(strict_types=1);

namespace App\Tenant\DTO;

/**
 * Uma conta (escritório) a que o usuário pertence, para a aba "Trocar conta" do modal
 * "Conta do escritório" (desenho bj-contas.js: "8 usuário(s) · primário: Fulano", selo ATUAL).
 */
final readonly class MinhaContaOutput
{
    public function __construct(
        public int $id,
        public string $nome,
        public int $usuarios,
        public ?string $primarioNome,
        public bool $atual,
        public bool $souTitular,
    ) {
    }
}
