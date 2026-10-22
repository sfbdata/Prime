<?php

declare(strict_types=1);

namespace App\Tarefa\DTO;

use App\Tarefa\Enum\EtapaDaPasta;

/**
 * Uma caixa da esteira (desenho 04 de 22/10, dc L61): contador, atrasadas, gargalo e o que o escritório mudou
 * nela. `nomeDaCaixa`/`nomeDaEtapa` nulos = o padrão ("CAIXA N" e o nome do catálogo), que o template traduz.
 */
final class CaixaNaEsteiraOutput
{
    /**
     * @param list<string> $avisados nomes de quem é avisado quando chega demanda
     * @param list<int>    $idsDosAvisados
     */
    public function __construct(
        public readonly EtapaDaPasta $etapa,
        public readonly int $total,
        public readonly int $atrasadas,
        public readonly bool $gargalo,
        public readonly ?string $nomeDaCaixa = null,
        public readonly ?string $nomeDaEtapa = null,
        public readonly array $avisados = [],
        public readonly array $idsDosAvisados = [],
    ) {
    }

    public function posicao(): int
    {
        return $this->etapa->posicao();
    }

    public function ultima(): bool
    {
        return $this->etapa === EtapaDaPasta::Encerramento;
    }
}
