<?php

declare(strict_types=1);

namespace App\Inteligencia\DTO;

/**
 * As análises de UMA pasta (mais recente primeiro, sem as excluídas) mais o que o botão da aba
 * Push precisa saber: se já houve análise ("Gerar nova análise" em vez de "Resumir com IA") e
 * quantas movimentações ainda não foram lidas por nenhuma análise concluída (title do botão).
 */
final readonly class AnalisesDaPastaOutput
{
    /**
     * @param list<AnaliseOutput> $analises
     */
    public function __construct(
        public array $analises,
        public ?AnaliseOutput $ultimaConcluida,
        public int $totalMovimentacoes,
        public int $naoAnalisadas,
    ) {
    }

    /**
     * A instalação sem IA (plataforma não configurada): nenhuma consulta às tabelas da IA, nenhuma
     * análise. `$movimentacoes` (o que a aba já tem para ler) só decide se o botão — desabilitado,
     * com o motivo real — aparece.
     */
    public static function semInteligenciaNaPlataforma(int $movimentacoes): self
    {
        return new self(analises: [], ultimaConcluida: null, totalMovimentacoes: $movimentacoes, naoAnalisadas: $movimentacoes);
    }

    public function temAnalise(): bool
    {
        return $this->analises !== [];
    }

    public function temEmAndamento(): bool
    {
        foreach ($this->analises as $analise) {
            if ($analise->emAndamento) {
                return true;
            }
        }

        return false;
    }

    public function total(): int
    {
        return count($this->analises);
    }
}
