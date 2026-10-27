<?php

declare(strict_types=1);

namespace App\Dashboard\Inteligencia;

use App\Dashboard\DTO\ComparacaoDaPessoaOutput;
use App\Dashboard\DTO\LinhaAdvogadoDashboardOutput;
use App\Dashboard\Preferencia\ColunasExtrasDoDashboard as Extras;

/**
 * Os números reais de UMA pessoa que as regras de {@see AnaliseDaPessoa} leem, já com o "Zerar" aplicado:
 * número de coluna zerada vira null e a regra que dependeria dele não dispara (nada é inferido do que a
 * tabela não mostra). Limiares do desenho (dc `bluejus-equipe.js`, sensibilidade moderada): queda de
 * conclusões de 15%, carga que sobe 20%, fila 1,5 vez a média da equipe (ou 0,5), vencidas 40% da fila.
 */
final readonly class SinaisDaPessoa
{
    private const PISO_DA_FILA = 3;

    public function __construct(
        public ?int $variacaoConclusoes,
        public ?int $variacaoCarga,
        public ?int $ativas,
        public ?int $vencidas,
        public ?float $media,
        public ?int $emRevisao,
    ) {
    }

    public static function de(LinhaAdvogadoDashboardOutput $l, ?ComparacaoDaPessoaOutput $conclusoes, ?float $mediaDaEquipe): self
    {
        $zerada = static fn (string $coluna): bool => in_array($coluna, $l->colunasZeradas, true);
        $ant    = $l->totalMetasAnterior;
        $carga  = $ant !== null && $ant > 0 && !$zerada('metas') ? (int) round(($l->totalMetas - $ant) / $ant * 100) : null;
        $ativas = $zerada('metas_ativas') ? null : $l->metasAtivas;

        return new self(
            $conclusoes?->variacao(),
            $carga,
            $ativas,
            $zerada('metas_vencidas') ? null : $l->metasVencidas,
            $ativas === null ? null : $mediaDaEquipe,
            $zerada(Extras::METAS_REVISAO) ? null : (isset($l->extras[Extras::METAS_REVISAO]) ? (int) $l->extras[Extras::METAS_REVISAO] : null),
        );
    }

    public function quedaDeConclusoes(): bool
    {
        return $this->variacaoConclusoes !== null && $this->variacaoConclusoes <= -15;
    }

    public function cargaSubiu(): bool
    {
        return $this->variacaoCarga !== null && $this->variacaoCarga >= 20;
    }

    /** Vencidas como parte da fila ativa; sem fila, sem razão. */
    public function razaoDeVencidas(): ?float
    {
        return $this->ativas !== null && $this->vencidas !== null && $this->ativas > 0 ? $this->vencidas / $this->ativas : null;
    }

    public function vencidasPedemAtencao(): bool
    {
        $razao = $this->razaoDeVencidas();

        return $this->vencidas !== null && $this->vencidas > 0 && ($this->vencidas >= 4 || ($razao !== null && $razao >= 0.4));
    }

    /** Piso de 3 ativas: com fila de 1 ou 2 metas "acima da média" é só efeito de equipe pequena. */
    public function filaAcimaDaMedia(): bool
    {
        return $this->ativas !== null && $this->media !== null && $this->media > 0 && $this->ativas >= self::PISO_DA_FILA && $this->ativas >= $this->media * 1.5;
    }

    public function filaAbaixoDaMedia(): bool
    {
        return $this->ativas !== null && $this->media !== null && $this->media > 0 && $this->ativas <= $this->media * 0.5;
    }
}
