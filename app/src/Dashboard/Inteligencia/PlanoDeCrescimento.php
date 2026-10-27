<?php

declare(strict_types=1);

namespace App\Dashboard\Inteligencia;

/**
 * Um nível do plano "Aumentar o nível" (dc L1284-1325), calculado por regras fixas sobre a meta de
 * pastas do mês (ver MontarPlanosDeCrescimento). Nada aqui é estimativa de modelo: cada número sai
 * da meta atual, do realizado, dos dias úteis e das pastas criadas por pessoa.
 */
final readonly class PlanoDeCrescimento
{
    /**
     * @param list<PessoaDoPlano>   $pessoas
     * @param list<MarcoDoPlano>    $marcos
     * @param list<AlavancaDoPlano> $alavancas
     */
    public function __construct(
        public NivelDeCrescimento $nivel,
        /** A meta de pastas do mês hoje (a base do percentual). */
        public int $metaAtual,
        public int $meta,
        public int $realizado,
        public int $faltam,
        /** Dias úteis que restam (no mínimo 1, como o desenho). */
        public int $dias,
        public float $ritmo,
        public float $ritmoAtual,
        public ViabilidadeDoPlano $viabilidade,
        public array $pessoas,
        public array $marcos,
        public array $alavancas,
    ) {
    }

    /** O nível que abre escolhido (+10%, como o desenho). */
    public function padrao(): bool
    {
        return $this->nivel === NivelDeCrescimento::PADRAO;
    }

    public function jaAlcancado(): bool
    {
        return $this->faltam === 0;
    }

    /** Gravar a mesma meta que já vale não muda nada: o botão não aparece. */
    public function aplicavel(): bool
    {
        return $this->meta !== $this->metaAtual;
    }

    /** Acréscimo real sobre a meta atual, em %, depois do teto de 9999 (o desenho mostra o do nível). */
    public function ganhoReal(): int
    {
        return $this->metaAtual > 0 ? (int) round(($this->meta / $this->metaAtual - 1) * 100) : 0;
    }
}
