<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

use App\Inteligencia\Enum\SecaoDoContexto;

/**
 * Uma seção do contexto da pasta já preparada para o prompt: as linhas (texto plano, neutralizado,
 * mascarado, truncado) e quantas ficaram de fora pelo limite — o corte é declarado ao modelo e
 * gravado em `contexto_resumo`, nunca silencioso.
 */
final readonly class SecaoDeContexto
{
    /**
     * @param list<string> $linhas
     */
    public function __construct(
        public SecaoDoContexto $secao,
        public array $linhas,
        public int $omitidas = 0,
    ) {
    }

    public function vazia(): bool
    {
        return $this->linhas === [];
    }

    public function total(): int
    {
        return count($this->linhas);
    }
}
