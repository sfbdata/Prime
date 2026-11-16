<?php

declare(strict_types=1);

namespace App\Conector\Exception;

use App\Conector\Enum\MotivoDaRevogacaoDaConcessao;

/**
 * A renovação apresentada não gira e a concessão inteira caiu (`docs/specs/mcp-remoto-oauth.md`, 9.1 e 9.2: "renovação
 * que falha em qualquer item tem a concessão revogada e responde invalid_grant"). Quando sobe, a entidade já revogou a
 * concessão com {@see $motivo}; quem a pega grava (flush) e responde `invalid_grant`.
 */
class RenovacaoRecusada extends \DomainException
{
    public function __construct(
        public readonly MotivoDaRevogacaoDaConcessao $motivo,
        string $mensagem = 'A renovação não vale mais: a concessão caiu.',
    ) {
        parent::__construct($mensagem);
    }
}
