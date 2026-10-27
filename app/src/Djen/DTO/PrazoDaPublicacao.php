<?php

declare(strict_types=1);

namespace App\Djen\DTO;

/**
 * O prazo de uma publicação como a tela do Push o mostra (selo da linha e caixa PRAZO do modal).
 * Só leitura: o cálculo mora em {@see \App\Djen\Service\PrazoDaPublicacaoCalculador}.
 */
final class PrazoDaPublicacao
{
    public const ORIGEM_ESCOLHIDO = 'escolhido';
    public const ORIGEM_TEOR = 'teor';

    public function __construct(
        public readonly \DateTimeImmutable $vencimento,
        /** Primeiro dia da contagem; null quando o prazo veio do teor (a data pronta). */
        public readonly ?\DateTimeImmutable $inicio,
        public readonly ?int $dias,
        /** Dias úteis de amanhã até o vencimento; negativo = vencido. */
        public readonly int $restante,
        public readonly bool $cumprido,
        public readonly string $origem,
    ) {
    }

    public function vencido(): bool
    {
        return $this->restante < 0;
    }

    /** Fundo do selo da linha (desenho 11). */
    public function corDoSelo(): string
    {
        return match (true) {
            $this->restante < 0 => '#a3232b',
            $this->restante <= 2 => '#c0392b',
            $this->restante <= 5 => '#c76a12',
            default => '#e6edf2',
        };
    }

    public function textoDoSeloClaro(): bool
    {
        return $this->restante > 5;
    }

    /** Cor do texto "Faltam N dias úteis" do modal. */
    public function corDoRestante(): string
    {
        return match (true) {
            $this->restante <= 2 => '#a3232b',
            $this->restante <= 5 => '#c76a12',
            default => '#1e7b45',
        };
    }
}
