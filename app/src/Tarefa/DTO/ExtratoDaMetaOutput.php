<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

/**
 * A folha "Extrato de tramitação" da meta (desenho 04B de 22/10, L150; spec `minhas-metas-esteira-2210.md`, L9):
 * cabeçalho ("Meta #N · título · Pasta X", "Emitido em"), o bloco DEMANDA (criação, autor e descrição) e um bloco por
 * item do histórico, do mais antigo ao mais novo, com a mesma numeração ("DESPACHO N") da tela da meta.
 */
final class ExtratoDaMetaOutput
{
    /** @param list<ItemDaLinhaDoTempoOutput> $itens do mais antigo para o mais novo */
    public function __construct(
        public readonly int $numero,
        public readonly string $titulo,
        public readonly string $pasta,
        public readonly \DateTimeImmutable $emitidoEm,
        public readonly \DateTimeImmutable $criadaEm,
        public readonly string $autor,
        public readonly string $descricao,
        public readonly array $itens,
    ) {
    }
}
