<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

/**
 * Uma ferramenta (tool use) oferecida ao modelo: nome, descrição e o esquema JSON da entrada. Formato neutro de
 * fornecedor; o {@see \App\Inteligencia\Service\ProvedorAnthropic} a traduz para `{name, description, input_schema}`.
 * O modelo só PEDE a chamada: quem decide o que fazer com ela é o sistema, nunca o modelo.
 */
final readonly class FerramentaDeLinguagem
{
    /** @param array<string, mixed> $esquemaDaEntrada JSON Schema do objeto de entrada */
    public function __construct(
        public string $nome,
        public string $descricao,
        public array $esquemaDaEntrada,
    ) {
    }

    /** @return array{name: string, description: string, input_schema: array<string, mixed>} */
    public function paraAnthropic(): array
    {
        return ['name' => $this->nome, 'description' => $this->descricao, 'input_schema' => $this->esquemaDaEntrada];
    }
}
