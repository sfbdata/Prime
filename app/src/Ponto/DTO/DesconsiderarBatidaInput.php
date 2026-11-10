<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Entity\JustificativaPonto;

/**
 * Tirar uma batida da conta da folha (E10, plano-e10-ponto §6). O registro original fica guardado; quem desfaz é a
 * restauração. Dois caminhos, cada um com o seu construtor:
 *
 * - `daTela`: o gestor, pela ficha do colaborador, com a cabeça que a folha mostrou (outro ajuste no meio = recusa).
 * - `pelaAnalise`: a análise da justificativa (a recusa do trabalho externo da E12), ligada à justificativa e sem
 *   conferir a cabeça. A tela não restaura essa desconsideração (decisão A): ela só volta pela análise, quando a
 *   justificativa recusada volta para análise (`AnalisarJustificativaUseCase::reverter`).
 */
final class DesconsiderarBatidaInput
{
    private function __construct(
        public readonly Tenant $escritorio,
        public readonly User $pessoa,
        public readonly string $alvo,
        public readonly bool $conferirCabeca,
        public readonly ?int $cabecaEsperada,
        public readonly string $motivo,
        public readonly User $autor,
        public readonly ?JustificativaPonto $justificativa,
    ) {
    }

    /** O gestor, pela ficha: confere a cabeça que a folha mostrou (nula na batida nunca ajustada). */
    public static function daTela(Tenant $escritorio, User $pessoa, string $alvo, ?int $cabecaEsperada, string $motivo, User $autor): self
    {
        return new self($escritorio, $pessoa, $alvo, true, $cabecaEsperada, $motivo, $autor, null);
    }

    /** A análise da justificativa: ligada a ela e sem conferir a cabeça (o alvo é 'b{id do registro}'). */
    public static function pelaAnalise(
        Tenant $escritorio,
        User $pessoa,
        string $alvo,
        string $motivo,
        User $analista,
        JustificativaPonto $justificativa,
    ): self {
        return new self($escritorio, $pessoa, $alvo, false, null, $motivo, $analista, $justificativa);
    }
}
