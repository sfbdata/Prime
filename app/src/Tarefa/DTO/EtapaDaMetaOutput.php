<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

/**
 * O trilho "ETAPA DA PASTA" e as ações da lateral da meta (desenho 04B L43-44, L78-82). `trilho` vem na ordem da
 * sequência do tipo de demanda; `estado` de cada etapa: `atual`, `saltada`, `passada`, `proxima` ou `adiante`.
 */
final readonly class EtapaDaMetaOutput
{
    /**
     * @param list<array{nome: string, setor: string, icone: string, estado: string, dica: string}> $trilho
     * @param list<string>                                                                          $saltadas nomes das etapas saltadas
     */
    public function __construct(
        public array $trilho,
        public array $saltadas,
        public bool $aberta,
        public bool $podeConcluir,
        public bool $podeDevolver,
        public ?string $proxima,
        public ?string $setorDaProxima,
        public string $devolverPara,
    ) {
    }
}
