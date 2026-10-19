<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Enum;

/**
 * Ações de situação da vaga (desenho 06, l.979–983 e o menu da l.1001). Todas são da Central com
 * `talentos` (o `gere` do desenho); o dono externo só edita ou corrige e reenvia (`EditarVagaUseCase`).
 */
enum AcaoVaga: string
{
    case Aprovar = 'aprovar';
    case Reprovar = 'reprovar';
    case Pausar = 'pausar';
    case Reabrir = 'reabrir';
    case OfertarNovamente = 'ofertar';
    case Encerrar = 'encerrar';

    /** @return list<SituacaoVaga> */
    public function origens(): array
    {
        return match ($this) {
            self::Aprovar, self::Reprovar => [SituacaoVaga::Pendente],
            self::Pausar => [SituacaoVaga::Aberta],
            self::Reabrir => [SituacaoVaga::Pausada, SituacaoVaga::Ocupada, SituacaoVaga::Expirada, SituacaoVaga::Encerrada],
            self::OfertarNovamente => [SituacaoVaga::Pausada, SituacaoVaga::Ocupada, SituacaoVaga::Expirada, SituacaoVaga::Encerrada],
            self::Encerrar => [SituacaoVaga::Aberta, SituacaoVaga::Pausada, SituacaoVaga::Ocupada, SituacaoVaga::Expirada],
        };
    }

    public function destino(): SituacaoVaga
    {
        return match ($this) {
            self::Aprovar, self::Reabrir, self::OfertarNovamente => SituacaoVaga::Aberta,
            self::Reprovar => SituacaoVaga::Reprovada,
            self::Pausar => SituacaoVaga::Pausada,
            self::Encerrar => SituacaoVaga::Encerrada,
        };
    }

    /** "Ofertar novamente" só existe para a oferta com tempo (l.1001). */
    public function valePara(bool $oferta): bool
    {
        return $this !== self::OfertarNovamente || $oferta;
    }

    /** O motivo da reprovação é opcional ("Motivo para não publicar (o solicitante verá):", l.980). */
    public function pedeMotivo(): bool
    {
        return $this === self::Reprovar;
    }

    /**
     * Rótulo, ícone e tipo do botão, como no menu da l.1001.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public function botao(bool $oferta, int $duracao): array
    {
        return match ($this) {
            self::Aprovar => ['Aprovar e publicar', 'bi-megaphone', 'pri'],
            self::Reprovar => ['Não aprovar', 'bi-x-circle', 'perigo'],
            self::Pausar => ['Pausar inscrições', 'bi-pause-circle', ''],
            self::Reabrir => ['Reabrir vaga', 'bi-play-circle', ''],
            self::OfertarNovamente => [sprintf('Ofertar novamente (%d min)', $duracao), 'bi-arrow-repeat', ''],
            self::Encerrar => [$oferta ? 'Finalizar oferta' : 'Encerrar vaga', 'bi-x-circle', ''],
        };
    }

    /**
     * Linha do "HISTÓRICO DA OFERTA" (l.979–983): ícone, cor e texto, com quem fez.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public function historico(string $quem, ?string $motivo, int $duracao): array
    {
        return match ($this) {
            self::Aprovar => ['bi-check2-circle', '#1e7b45', 'Aprovada e publicada por ' . $quem],
            self::Reprovar => ['bi-x-circle', '#a3232b', 'Não aprovada por ' . $quem . ($motivo !== null ? ': ' . $motivo : '')],
            self::Pausar => ['bi-pause-circle', SituacaoVaga::Pausada->cor(), 'Pausada por ' . $quem],
            self::Reabrir => ['bi-play-circle', SituacaoVaga::Aberta->cor(), 'Reaberta por ' . $quem],
            self::OfertarNovamente => ['bi-arrow-repeat', '#0a6fa0', sprintf('Ofertada novamente por %d min', $duracao)],
            self::Encerrar => ['bi-x-circle', SituacaoVaga::Encerrada->cor(), 'Encerrada por ' . $quem],
        };
    }
}
