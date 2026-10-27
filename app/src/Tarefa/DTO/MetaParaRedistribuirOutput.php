<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

/** Uma meta ativa da pessoa na tela de redistribuição, com o destino sugerido (nulo se ninguém elegível vê a pasta). */
final readonly class MetaParaRedistribuirOutput
{
    /** @param list<int> $destinosPossiveis ids de quem pode receber (veem a pasta da meta) */
    public function __construct(
        public int $id,
        public string $titulo,
        public string $pasta,
        public ?\DateTimeImmutable $prazo,
        public ?int $destinoSugeridoId,
        public array $destinosPossiveis,
    ) {
    }
}
