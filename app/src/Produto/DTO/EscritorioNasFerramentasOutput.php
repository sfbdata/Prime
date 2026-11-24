<?php

declare(strict_types=1);

namespace App\Produto\DTO;

use App\Produto\Enum\Produto;

/**
 * Uma linha da grade da aba "Ferramentas" do Master: o escritório, se ele é completo (sem linha ou com `completo`
 * ativa) e o estado de cada ferramenta. `travadas` são as desligadas pelo BlueJus (D16): só o Master religa.
 */
final class EscritorioNasFerramentasOutput
{
    /**
     * @param list<Produto> $ligadas  ferramentas ligadas de fato (Voto e I.A. também precisam estar liberados)
     * @param list<Produto> $travadas ferramentas desligadas pelo BlueJus
     */
    public function __construct(
        public readonly int $tenantId,
        public readonly string $nome,
        public readonly bool $completo,
        public readonly array $ligadas,
        public readonly array $travadas,
    ) {
    }
}
