<?php

declare(strict_types=1);

namespace App\Conector\Exception;

/**
 * Uma renovação já girada voltou fora da janela de tolerância, ou por outro app de I.A.: sinal de roubo
 * (`docs/specs/mcp-remoto-oauth.md`, 9.1). Quando sobe, a concessão já foi revogada pela entidade; quem a pega grava
 * (flush) e responde `invalid_grant`.
 */
final class RenovacaoReutilizada extends \DomainException
{
    public function __construct()
    {
        parent::__construct('A renovação foi reapresentada fora da janela ou por outro app: a concessão caiu.');
    }
}
