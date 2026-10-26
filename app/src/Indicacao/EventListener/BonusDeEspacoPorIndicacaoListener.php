<?php

declare(strict_types=1);

namespace App\Indicacao\EventListener;

use App\Auth\Event\EmailDaContaConfirmado;
use App\Indicacao\Service\BonusDoCadastroPorIndicacao;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Quando a pessoa confirma o e-mail, a conta que chegou por indicação ganha o bônus de espaço (spec
 * `bonus-de-espaco-por-indicacao.md`, regra 2). O outro gatilho, a conta que já nasce confirmada, está no
 * {@see CadastroPeloLinkListener}. Não falha nunca: o serviço registra o erro em log.
 */
final class BonusDeEspacoPorIndicacaoListener
{
    public function __construct(
        private readonly BonusDoCadastroPorIndicacao $bonus,
    ) {
    }

    #[AsEventListener]
    public function aoConfirmarEmail(EmailDaContaConfirmado $evento): void
    {
        $this->bonus->tentar($evento->userId);
    }
}
