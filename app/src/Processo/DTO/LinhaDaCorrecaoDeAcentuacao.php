<?php

declare(strict_types=1);

namespace App\Processo\DTO;

/** Um nome de órgão estragado: onde está, quantas linhas, e a reversão (nula quando não sai limpa e fica intocado). */
final readonly class LinhaDaCorrecaoDeAcentuacao
{
    public const NO_PROCESSO = 'processo';
    public const NA_MOVIMENTACAO = 'movimentação';

    public function __construct(
        public string $onde,
        public string $antes,
        public ?string $depois,
        public int $quantidade,
    ) {
    }

    public function reverte(): bool
    {
        return $this->depois !== null;
    }
}
