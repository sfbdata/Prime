<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

/**
 * Uma chamada de ferramenta que o modelo pediu (bloco `tool_use` completo): o nome e a entrada JÁ decodificada. É dado
 * não confiável: quem a recebe valida nome e entrada antes de qualquer uso. Nunca é logada (leva dado da conversa).
 */
final readonly class ChamadaDeFerramenta
{
    /** @param array<string, mixed> $entrada */
    public function __construct(
        public string $nome,
        public array $entrada,
    ) {
    }
}
