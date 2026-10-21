<?php

declare(strict_types=1);

namespace App\Processo\DTO;

use Symfony\Component\HttpFoundation\Request;

/**
 * Corpo do "Salvar" da edição do cartão do processo (P5b): os 11 campos crus, como o formulário os
 * manda. Flags: "1" = Sim, "0" = Não, qualquer outro valor = Não informado. Quem interpreta é o
 * `SalvarDadosManuaisDoProcessoUseCase`.
 */
final readonly class DadosDoProcessoInput
{
    /**
     * @param array<string, string> $textos chave do campo → texto digitado
     * @param array<string, ?bool>  $flags  chave da flag → Sim/Não/nulo
     */
    public function __construct(
        public array $textos,
        public array $flags,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        // `all()` e não `get()`: um campo mandado como lista vira vazio em vez de 400.
        $corpo = $request->request->all();
        $textos = [];
        foreach (['classe', 'assuntos', 'jurisdicao', 'orgao', 'distribuicao', 'valorCausa'] as $chave) {
            $valor = $corpo[$chave] ?? null;
            $textos[$chave] = is_string($valor) ? $valor : '';
        }

        $flags = [];
        foreach (['segredo', 'digital', 'gratuita', 'tutela', 'prioridade'] as $chave) {
            $valor = $corpo[$chave] ?? null;
            $flags[$chave] = match ($valor) {
                '1' => true,
                '0' => false,
                default => null,
            };
        }

        return new self($textos, $flags);
    }
}
