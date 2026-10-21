<?php

declare(strict_types=1);

namespace App\Contribua\DTO;

/** Edição de um plano pelo Master (tela de Planos do domínio Perfis). `limiteUsuarios` nulo = sem limite. */
final readonly class AlterarPlanoInput
{
    public function __construct(
        public string $codigo,
        public string $nome,
        public int $cotaGb,
        public int $precoCentavos,
        public ?int $limiteUsuarios,
        public string $descricao,
        public bool $ativo,
    ) {
    }
}
