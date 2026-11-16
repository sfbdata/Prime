<?php

declare(strict_types=1);

namespace App\Voto\DTO;

use App\Voto\Enum\EstadoDaPauta;

/**
 * Uma linha das "Estatísticas de todas as votações" do Master: uma pauta, só em números agregados. Nunca nome de
 * pessoa, unidade ou e-mail (o que existe aqui é escritório, entidade e os títulos escritos pela mesa).
 */
final readonly class EstatisticaDaVotacao
{
    public const QUORUM_ATINGIDO = 'atingido';
    public const QUORUM_NAO_ATINGIDO = 'nao_atingido';
    public const QUORUM_NAO_EXIGIDO = 'nao_exigido';

    /** @param ?string $quorum um dos QUORUM_*; null enquanto a pauta não encerrou (aberta nunca leva parcial) */
    public function __construct(
        public string $escritorio,
        public ?string $entidade,
        public string $assembleia,
        public string $pauta,
        public string $categoria,
        public EstadoDaPauta $estado,
        public ?\DateTimeImmutable $encerramento,
        public int $aptos,
        public int $votantes,
        public ?string $quorum,
    ) {
    }

    public function realizada(): bool
    {
        return EstadoDaPauta::Encerrada === $this->estado;
    }

    /** Participação em % por unidades (votantes ÷ aptos), 1 casa; 0 sem aptos. */
    public function participacaoPct(): float
    {
        return $this->aptos > 0 ? round($this->votantes / $this->aptos * 100, 1) : 0.0;
    }

    /** "66,7%"; "50%" quando a casa decimal é zero (como o toLocaleString do desenho). */
    public function participacao(): string
    {
        return self::formatarPercentual($this->participacaoPct());
    }

    public static function formatarPercentual(float $pct): string
    {
        return rtrim(rtrim(number_format($pct, 1, ',', '.'), '0'), ',') . '%';
    }
}
