<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/**
 * Uma linha das "Estatísticas de todas as votações" do Master: uma pauta, só em números agregados. Nunca nome de
 * pessoa, unidade ou e-mail (o que existe aqui é escritório, entidade e os títulos escritos pela mesa).
 */
final readonly class EstatisticaDaVotacao
{
    public const SITUACOES = ['aguardando', 'aberta', 'encerrada', 'cancelada', 'retirada'];
    /** Cor do chip por situação: as da pauta no painel (PautaNoPainel::CORES) e as duas que o desenho não pinta. */
    public const CORES = ['aguardando' => '#0a6fa0', 'aberta' => '#1e7b45', 'encerrada' => '#8a6200', 'cancelada' => '#a3232b', 'retirada' => '#5b7383'];
    public const QUORUM_ATINGIDO = 'atingido';
    public const QUORUM_NAO_ATINGIDO = 'nao_atingido';
    public const QUORUM_NAO_EXIGIDO = 'nao_exigido';

    /**
     * @param string  $situacao   uma das SITUACOES
     * @param ?string $quorum     um dos QUORUM_*; null na pauta cancelada ou retirada (não há quórum a medir)
     * @param ?\DateTimeImmutable $encerramento o fim: o encerramento real, senão o prazo, senão o horário programado
     */
    public function __construct(
        public string $escritorio,
        public ?string $entidade,
        public string $assembleia,
        public string $pauta,
        public string $categoria,
        public string $situacao,
        public ?\DateTimeImmutable $encerramento,
        public int $aptos,
        public int $votantes,
        public ?string $quorum,
    ) {
    }

    public function realizada(): bool
    {
        return 'encerrada' === $this->situacao;
    }

    public function cor(): string
    {
        return self::CORES[$this->situacao];
    }

    /** "dd/mm/aaaa hh:mm" no horário de São Paulo (o "até" do desenho); null sem fim. */
    public function fim(): ?string
    {
        return $this->encerramento?->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->format('d/m/Y H:i');
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
