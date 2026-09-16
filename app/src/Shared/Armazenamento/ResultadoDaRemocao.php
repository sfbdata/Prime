<?php

declare(strict_types=1);

namespace App\Shared\Armazenamento;

/**
 * O que uma remoção pós-COMMIT conseguiu fazer.
 *
 * `naoRemovidas` traz, em texto, o que ficou no storage: a chave ({@see ChaveDeArquivo::comoTexto()})
 * na remoção pós-transação, ou `escopo/categoria/caminho relativo` na remoção por prefixo. Nunca
 * caminho absoluto de disco.
 */
final readonly class ResultadoDaRemocao
{
    /**
     * @param list<string> $naoRemovidas
     */
    public function __construct(
        public int $removidos,
        public array $naoRemovidas,
    ) {
    }

    public function completa(): bool
    {
        return $this->naoRemovidas === [];
    }
}
