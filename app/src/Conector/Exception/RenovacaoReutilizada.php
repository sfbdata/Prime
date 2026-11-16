<?php

declare(strict_types=1);

namespace App\Conector\Exception;

use App\Conector\Enum\MotivoDaRevogacaoDaConcessao;

/**
 * Uma renovação já girada voltou fora da janela de tolerância, depois que a renovação gerada por ela já foi usada ou
 * revogada, ou por outro app de I.A.: sinal de roubo (`docs/specs/mcp-remoto-oauth.md`, 9.1). Quando sobe, a concessão
 * já foi revogada pela entidade com `renovacao_reutilizada`; quem a pega grava (flush) e responde `invalid_grant`.
 */
final class RenovacaoReutilizada extends RenovacaoRecusada
{
    public function __construct()
    {
        parent::__construct(
            MotivoDaRevogacaoDaConcessao::RenovacaoReutilizada,
            'A renovação foi reapresentada fora da janela ou por outro app: a concessão caiu.',
        );
    }
}
