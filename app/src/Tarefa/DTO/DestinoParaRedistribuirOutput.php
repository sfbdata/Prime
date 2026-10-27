<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

/** Quem pode receber metas na tela de redistribuição, com quantas metas ativas já tem. */
final readonly class DestinoParaRedistribuirOutput
{
    public function __construct(
        public int $id,
        public string $nome,
        public int $ativas,
    ) {
    }
}
