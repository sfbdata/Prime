<?php

declare(strict_types=1);

namespace App\Novidades\DTO;

/** Uma versão lançada e o que ela trouxe, em linguagem de escritório. */
final class VersaoComNovidades
{
    /** @param list<string> $itens */
    public function __construct(
        public readonly string $versao,
        public readonly \DateTimeImmutable $data,
        public readonly array $itens,
    ) {}
}
