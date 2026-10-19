<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Enum;

/**
 * Ações sobre a vaga (spec VS 18.10.26 §4.1). Os rótulos e a ordem dos botões vinham do código do
 * desenho que se perdeu no corte: provisórios até o zip.
 */
enum AcaoVaga: string
{
    case Aprovar = 'aprovar';
    case Reprovar = 'reprovar';
    case Pausar = 'pausar';
    case Retomar = 'retomar';
    case Preencher = 'preencher';
    case Encerrar = 'encerrar';

    /** @return list<SituacaoVaga> */
    public function origens(): array
    {
        return match ($this) {
            self::Aprovar, self::Reprovar => [SituacaoVaga::Pendente],
            self::Pausar, self::Preencher => [SituacaoVaga::Aberta],
            self::Retomar => [SituacaoVaga::Pausada],
            self::Encerrar => [SituacaoVaga::Pendente, SituacaoVaga::Aberta, SituacaoVaga::Pausada],
        };
    }

    public function destino(): SituacaoVaga
    {
        return match ($this) {
            self::Aprovar, self::Retomar => SituacaoVaga::Aberta,
            self::Reprovar => SituacaoVaga::Reprovada,
            self::Pausar => SituacaoVaga::Pausada,
            self::Preencher => SituacaoVaga::Ocupada,
            self::Encerrar => SituacaoVaga::Encerrada,
        };
    }

    /** Só a Central aprova e reprova; o resto também cabe ao escritório dono da vaga. */
    public function ehDaCentral(): bool
    {
        return $this === self::Aprovar || $this === self::Reprovar;
    }

    public function exigeMotivo(): bool
    {
        return $this === self::Reprovar;
    }

    /** @return array{0: string, 1: string, 2: string} rótulo, ícone, tipo do botão */
    public function botao(): array
    {
        return match ($this) {
            self::Aprovar => ['Aprovar e divulgar', 'bi-check2-circle', 'pri'],
            self::Reprovar => ['Não aprovar', 'bi-x-circle', 'perigo'],
            self::Pausar => ['Pausar', 'bi-pause-circle', ''],
            self::Retomar => ['Retomar', 'bi-play-circle', 'pri'],
            self::Preencher => ['Marcar como preenchida', 'bi-person-check', ''],
            self::Encerrar => ['Encerrar', 'bi-stop-circle', 'perigo'],
        };
    }

    /** Linha do "HISTÓRICO DA OFERTA": ícone, cor e texto. */
    public function historico(?string $motivo): array
    {
        return match ($this) {
            self::Aprovar => ['bi-check2-circle', '#1e7b45', 'Vaga aprovada e divulgada na Central'],
            self::Reprovar => ['bi-x-circle', '#a3232b', 'Vaga não aprovada' . ($motivo !== null ? ': ' . $motivo : '')],
            self::Pausar => ['bi-pause-circle', '#9a5b00', 'Divulgação pausada'],
            self::Retomar => ['bi-play-circle', '#1e7b45', 'Divulgação retomada'],
            self::Preencher => ['bi-person-check', '#0b5f86', 'Vaga marcada como preenchida'],
            self::Encerrar => ['bi-stop-circle', '#6b8494', 'Vaga encerrada'],
        };
    }
}
