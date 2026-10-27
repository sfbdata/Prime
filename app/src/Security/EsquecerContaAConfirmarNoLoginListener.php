<?php

declare(strict_types=1);

namespace App\Security;

use App\Auth\Service\CadastroPeloFacebook;
use App\Auth\Service\CadastroPeloGoogle;
use App\Auth\Service\ContaAConfirmar;
use App\Entity\Auth\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Navegador compartilhado (spec `cadastro-exige-confirmacao.md`): a chave `conta_a_confirmar` é da pessoa que provou
 * ser dona daquela conta. Quando OUTRA conta entra com sucesso, a chave sai; o login usa a estratégia "migrate", que
 * preservaria o atributo, e a próxima pessoa veria o e-mail da anterior no /login e poderia disparar o reenvio.
 * Pelo mesmo motivo sai a identidade do Google que esperava o cadastro (`CadastroPeloGoogle`): qualquer entrada
 * com sucesso, inclusive a da conta que acabou de nascer dela, encerra a espera. O mesmo vale para a identidade do
 * Facebook (`CadastroPeloFacebook`).
 */
#[AsEventListener(event: LoginSuccessEvent::class, dispatcher: 'security.event_dispatcher.main')]
final class EsquecerContaAConfirmarNoLoginListener
{
    public function __construct(
        private readonly ContaAConfirmar $contaAConfirmar,
    ) {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $request = $event->getRequest();
        if (!$request->hasSession()) {
            return;
        }

        $sessao = $request->getSession();
        CadastroPeloGoogle::esquecer($sessao);
        CadastroPeloFacebook::esquecer($sessao);
        $id = $this->contaAConfirmar->idNaSessao($sessao);
        $user = $event->getUser();
        if ($id !== null && (!$user instanceof User || $user->getId() !== $id)) {
            ContaAConfirmar::esquecer($sessao);
        }
    }
}
