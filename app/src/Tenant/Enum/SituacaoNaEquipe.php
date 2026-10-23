<?php

declare(strict_types=1);

namespace App\Tenant\Enum;

/**
 * Situação da pessoa na equipe de UM escritório (desenho bj-contas.js, `SIT`; spec docs/specs/equipe-da-conta.md §2).
 * Só o primário do escritório ou o Master altera; o vínculo só fica ativo quando aprovado.
 */
enum SituacaoNaEquipe: string
{
    case Aprovado  = 'aprovado';
    case EmAnalise = 'em_analise';
    case Suspenso  = 'suspenso';

    public function rotulo(): string
    {
        return match ($this) {
            self::Aprovado  => 'APROVADO',
            self::EmAnalise => 'EM ANÁLISE',
            self::Suspenso  => 'SUSPENSO',
        };
    }

    /** Mensagem de quem tenta entrar no escritório (barreira (d) do acesso). */
    public function mensagemDeAcesso(string $escritorio): string
    {
        return match ($this) {
            self::Aprovado  => '',
            self::EmAnalise => sprintf('Seu acesso ao escritório %s está em análise pelo usuário primário.', $escritorio),
            self::Suspenso  => sprintf('Seu acesso ao escritório %s está suspenso pelo usuário primário. Seus dados e o histórico continuam guardados.', $escritorio),
        };
    }
}
