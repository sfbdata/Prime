<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

use App\Tarefa\Enum\EtapaDaPasta;

/**
 * A esteira da pasta, GERAL DO ESCRITÓRIO (desenho 04 de 22/10, dc L61): as 8 caixas na ordem, o resumo
 * "{N} metas abertas no escritório · {M} atrasadas", quem pode configurar caixa (D2 da spec) e, só para quem
 * pode, a equipe que o modal oferece no @.
 */
final class EsteiraDaPastaOutput
{
    /**
     * @param list<CaixaNaEsteiraOutput> $caixas
     * @param list<MembroDaEquipeOutput> $membros vazio para quem não pode configurar
     */
    public function __construct(
        public readonly array $caixas,
        public readonly bool $podeConfigurar,
        public readonly array $membros = [],
    ) {
    }

    public function totalDeAbertas(): int
    {
        return array_sum(array_map(static fn (CaixaNaEsteiraOutput $c): int => $c->total, $this->caixas));
    }

    public function totalDeAtrasadas(): int
    {
        return array_sum(array_map(static fn (CaixaNaEsteiraOutput $c): int => $c->atrasadas, $this->caixas));
    }

    public function caixa(EtapaDaPasta $etapa): ?CaixaNaEsteiraOutput
    {
        foreach ($this->caixas as $caixa) {
            if ($caixa->etapa === $etapa) {
                return $caixa;
            }
        }

        return null;
    }
}
