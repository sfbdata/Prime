<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

/**
 * Uma pasta já lida do banco, no formato que o analisador precisa (desenho 1.2.3, `spAnalisar`
 * L4515-4517): número, cliente, responsável, processo principal, se está marcada como
 * administrativa e as FONTES de texto (rótulo de onde veio + texto).
 *
 * `registros` serve só ao resumo para despacho ("N registro(s), último dd/mm por Fulano").
 */
final class PastaParaAnaliseSemProcesso
{
    /**
     * @param list<array{rotulo: string, texto: string}>             $fontes
     * @param list<array{em: \DateTimeImmutable, autor: string}>     $registros
     */
    public function __construct(
        public readonly int $id,
        public readonly string $numero,
        public readonly string $cliente,
        public readonly string $responsavel,
        public readonly ?int $responsavelId,
        public readonly ?string $processoNumero,
        public readonly ?string $processoTribunal,
        public readonly bool $marcada,
        public readonly array $fontes,
        public readonly array $registros,
        public readonly int $totalDocumentos,
    ) {
    }
}
