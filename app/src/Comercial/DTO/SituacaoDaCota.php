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
     * A chave do episódio para o "Dispensar": o estado e, nas pausadas, o dia em que a vaga volta (o horário anda a
     * cada envio liberado na janela corrida; o dia, não). Null = sem aviso.
     */
    public function chaveDoEpisodio(): ?string
    {
        $estado = $this->estado();
        if ($estado === null) {
            return null;
        }

        return $estado === self::PAUSADAS && $this->liberaEm !== null ? $estado . ':' . $this->liberaEm->format('Y-m-d') : $estado;
    }
}
