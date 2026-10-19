<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

use Symfony\Component\HttpFoundation\Request;

/**
 * Corpo do "Salvar" da edição do cartão (P4h): `resumo` e `pontos[i]`, com `i` = posição do ponto na
 * lista exibida. Ponto ausente = removido. Valor que não é texto vira vazio (e o ponto sai).
 */
final readonly class EditarAnaliseInput
{
    /**
     * @param array<int, string> $pontos
     */
    public function __construct(
        public string $resumo,
        public array $pontos,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $resumo = $request->request->get('resumo');
        $bruto = $request->request->all()['pontos'] ?? [];

        $pontos = [];
        if (is_array($bruto)) {
            foreach ($bruto as $i => $texto) {
                if (is_int($i) || ctype_digit((string) $i)) {
                    $pontos[(int) $i] = is_string($texto) ? $texto : '';
                }
            }
        }

        return new self(is_string($resumo) ? $resumo : '', $pontos);
    }
}
