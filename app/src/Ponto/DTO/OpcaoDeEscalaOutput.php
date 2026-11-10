<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

/**
 * Uma opção da coluna "Escala" da aba Equipe (desenho 16 L215). `id` vazio é a opção que descreve a jornada atual
 * quando nenhuma das 4 escalas do desenho bate com ela: vem marcada e não pode ser escolhida de volta.
 */
final readonly class OpcaoDeEscalaOutput
{
    public function __construct(
        public string $id,
        public string $rotulo,
        public bool $marcada,
    ) {
    }
}
