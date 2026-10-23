<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

/**
 * Retrato da cota de envio de e-mail nas últimas 24 h corridas (spec comercial-e-emails.md §3.6), para o aviso do
 * Master. `perto` = os enviados chegaram ao percentual do aviso; `campanhasPausadas` = há campanha esperando e sem vaga;
 * `liberaEm` = quando a vaga das campanhas volta (só com as campanhas pausadas).
 */
final class SituacaoDaCota
{
    public const PERTO = 'perto';
    public const PAUSADAS = 'pausadas';
    public const CHAVE_VALIDA = '/^(perto|pausadas):\d{4}-\d{2}-\d{2}$/';

    public function __construct(
        public readonly int $enviados24h,
        public readonly int $teto,
        public readonly int $percentual,
        public readonly bool $perto,
        public readonly bool $campanhasPausadas,
        public readonly ?\DateTimeImmutable $liberaEm,
    ) {
    }

    /** O estado do aviso: pausadas manda sobre perto; null = sem aviso. */
    public function estado(): ?string
    {
        if ($this->campanhasPausadas) {
            return self::PAUSADAS;
        }

        return $this->perto ? self::PERTO : null;
    }

    /**
     * A chave do episódio para o "Dispensar": o estado e um dia. Nas pausadas, o dia em que a vaga volta (o horário anda
     * a cada envio liberado na janela corrida; o dia, não); no perto, o dia de hoje (amanhã é outro episódio). Null = sem
     * aviso. Formato validado pelo controller ({@see self::CHAVE_VALIDA}).
     */
    public function chaveDoEpisodio(\DateTimeImmutable $hoje): ?string
    {
        $estado = $this->estado();
        if ($estado === null) {
            return null;
        }

        return $estado . ':' . ($estado === self::PAUSADAS && $this->liberaEm !== null ? $this->liberaEm : $hoje)->format('Y-m-d');
    }
}
