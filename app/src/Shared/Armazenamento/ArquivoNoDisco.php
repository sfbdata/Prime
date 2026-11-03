<?php

declare(strict_types=1);

namespace App\Shared\Armazenamento;

/**
 * O que a liberação do disco precisa saber de um arquivo que está no disco local: tamanho, última
 * modificação (`mtime`) e último acesso (`atime`), lidos por `lstat` (spec
 * `leitura-pelo-r2-e-liberacao-de-disco.md` §5.2).
 */
final readonly class ArquivoNoDisco
{
    public function __construct(
        public int $tamanhoBytes,
        public \DateTimeImmutable $modificadoEm,
        public \DateTimeImmutable $acessadoEm,
    ) {
    }
}
