<?php

declare(strict_types=1);

namespace App\Cobranca\DTO;

/**
 * A tabela inteira da aba Atividade: uma linha por pessoa (inclusive quem não trabalhou, zerado) mais o
 * total do setor no topo.
 *
 * O total soma TODAS as linhas exibidas — inclusive a de "Sem responsável". Excluí-la faria a tela
 * subnotificar o que de fato aconteceu no período, que é justamente o número que o gestor foi buscar.
 */
final class AtividadeEquipeOutput
{
    /**
     * @param list<AtividadePessoaOutput> $pessoas
     */
    public function __construct(
        public readonly array $pessoas,
        public readonly int $totalContatos,
        public readonly int $totalAtendidos,
        public readonly int $totalAcordos,
        public readonly int $totalBaixas,
        public readonly ?\DateTimeImmutable $ultimaAcaoDoSetor,
    ) {
    }

    public function vazia(): bool
    {
        return $this->totalContatos === 0
            && $this->totalAcordos === 0
            && $this->totalBaixas === 0
            && $this->ultimaAcaoDoSetor === null;
    }
}
