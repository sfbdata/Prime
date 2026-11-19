<?php

declare(strict_types=1);

namespace App\Profile\Enum;

/**
 * Fuso horário escolhido pela pessoa nas "Opções avançadas" do Meu Perfil (spec docs/specs/sistema-em-ingles.md, §2).
 * O valor é o identificador IANA (`America/Sao_Paulo`). Vale só para os registros de data e hora (`|data_hora`,
 * `|hora`), nunca para data pura (prazo, audiência), e os campos de digitar continuam no horário de Brasília.
 * Nulo no perfil = Brasília, o fuso do sistema.
 */
enum FusoHorario: string
{
    case Brasilia          = 'America/Sao_Paulo';
    case FernandoDeNoronha = 'America/Noronha';
    case Manaus            = 'America/Manaus';
    case RioBranco         = 'America/Rio_Branco';
    case Lisboa            = 'Europe/Lisbon';
    case Londres           = 'Europe/London';
    case Madri             = 'Europe/Madrid';
    case NovaYork          = 'America/New_York';
    case Chicago           = 'America/Chicago';
    case Denver            = 'America/Denver';
    case LosAngeles        = 'America/Los_Angeles';
    case Utc               = 'UTC';

    public const PADRAO = self::Brasilia;

    /** Chave do catálogo com o nome da cidade (bloco `opcoes_avancadas.fuso`). */
    public function chave(): string
    {
        return 'opcoes_avancadas.fuso.' . match ($this) {
            self::Brasilia          => 'brasilia',
            self::FernandoDeNoronha => 'noronha',
            self::Manaus            => 'manaus',
            self::RioBranco         => 'rio_branco',
            self::Lisboa            => 'lisboa',
            self::Londres           => 'londres',
            self::Madri             => 'madri',
            self::NovaYork          => 'nova_york',
            self::Chicago           => 'chicago',
            self::Denver            => 'denver',
            self::LosAngeles        => 'los_angeles',
            self::Utc               => 'utc',
        };
    }

    /** Diferença para o UTC agora, no formato "UTC−3" (com o horário de verão de quem tem). */
    public function deslocamento(?\DateTimeImmutable $agora = null): string
    {
        $segundos = (new \DateTimeZone($this->value))->getOffset($agora ?? new \DateTimeImmutable());
        if ($segundos === 0) {
            return 'UTC';
        }
        $horas = intdiv(abs($segundos), 3600);
        $minutos = intdiv(abs($segundos) % 3600, 60);

        return 'UTC' . ($segundos < 0 ? '−' : '+') . $horas . ($minutos > 0 ? ':' . str_pad((string) $minutos, 2, '0', STR_PAD_LEFT) : '');
    }
}
