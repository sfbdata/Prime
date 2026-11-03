<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Liberacao;

use App\Shared\Armazenamento\ChaveDeArquivo;

/**
 * A linha ABERTA do registro de liberação de uma chave (`restaurado_em` nulo): a cópia local saiu do
 * disco, e o que está no R2 foi provado com este SHA-256 e este tamanho antes da saída (spec
 * `leitura-pelo-r2-e-liberacao-de-disco.md` §4 e §5.3).
 */
final readonly class LiberacaoAberta
{
    public function __construct(
        public int $id,
        public string $lote,
        public string $chaveRemota,
        public string $sha256,
        public int $tamanhoBytes,
        /** Quando a cópia local saiu do disco (UTC). */
        public \DateTimeImmutable $liberadoEm,
        /** A chave local da linha (categoria, escopo, nome); null quando a linha não vira chave válida. */
        public ?ChaveDeArquivo $chave = null,
    ) {
    }
}
