<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

/** A tela de redistribuição de uma pessoa: as metas ativas dela (cadeado aplicado) e quem pode receber. */
final readonly class ListagemParaRedistribuirOutput
{
    /**
     * @param list<MetaParaRedistribuirOutput>    $metas
     * @param list<DestinoParaRedistribuirOutput> $destinos
     */
    public function __construct(
        public int $origemId,
        public string $origemNome,
        public array $metas,
        public array $destinos,
    ) {
    }
}
