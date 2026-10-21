<?php

declare(strict_types=1);

namespace App\Processo\DTO;

/** O que o reparo da acentuação do órgão viu e, com `aplicada`, gravou. */
final readonly class CorrecaoDeAcentuacaoOutput
{
    /** @param list<LinhaDaCorrecaoDeAcentuacao> $linhas */
    public function __construct(
        public array $linhas,
        public bool $aplicada,
    ) {
    }

    /** Linhas do banco que a reversão corrige (ou corrigiu). */
    public function corrigidas(): int
    {
        return array_sum(array_map(static fn (LinhaDaCorrecaoDeAcentuacao $l): int => $l->reverte() ? $l->quantidade : 0, $this->linhas));
    }

    /** @return list<LinhaDaCorrecaoDeAcentuacao> os nomes que não revertem limpo e ficam como estão */
    public function intocadas(): array
    {
        return array_values(array_filter($this->linhas, static fn (LinhaDaCorrecaoDeAcentuacao $l): bool => !$l->reverte()));
    }
}
