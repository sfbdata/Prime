<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\EventListener;

use App\Entity\Auth\User;
use App\Repository\UserTenantRepository;
use App\Security\GoogleAuthenticator;
use App\Security\UserAuthenticator;
use App\TrabalhoExterno\Repository\ConviteDemandaRepository;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Para onde vai o Convidado depois do login. O `UserAuthenticator` manda quem não tem escritório
 * para "selecionar escritório", e o Convidado (conta sem escritório, spec §8) ficaria parado numa
 * tela sem escritório nenhum para escolher. Aqui, e SÓ nesse caso (sem vínculo ativo, sem ser
 * super admin e com convite vigente), o destino vira o Trabalho Externo.
 * Quem tem vínculo com escritório fora do ar (suspenso ou excluído) segue para a seleção, onde vê o aviso
 * da conta (docs/specs/te-convidado-escritorio-fora-do-ar.md).
 *
 * Fica no módulo, como ouvinte do evento de login do firewall `main`, para não mexer no
 * autenticador de todos: quem tem escritório, o super admin e quem não tem convite seguem o
 * caminho de sempre. Não concede acesso nenhum: o perfil continua resolvido em cada requisição.
 *
 * Só o login pelo FORMULÁRIO (`UserAuthenticator`) ou pelo Google (`GoogleAuthenticator`) tem destino a trocar. O login automático
 * pelo cookie de "manter conectado" acontece no meio de uma requisição qualquer (um GET com
 * `?te=`, um POST da API) e deve deixá-la seguir: redirecionar ali perderia a ação.
 */
#[AsEventListener(event: LoginSuccessEvent::class, dispatcher: 'security.event_dispatcher.main')]
final class EntradaDoConvidadoListener
{
    public function __construct(
        private readonly UserTenantRepository $vinculos,
        private readonly ConviteDemandaRepository $convites,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $usuario = $event->getUser();
        if (!($event->getAuthenticator() instanceof UserAuthenticator || $event->getAuthenticator() instanceof GoogleAuthenticator)
            || !$usuario instanceof User
            || in_array('ROLE_SUPER_ADMIN', $usuario->getRoles(), true)
            || $this->vinculos->findActiveByUser($usuario) !== []
            || $this->vinculos->contasForaDoArDoUsuario($usuario) !== []
            || $this->convites->vigentesDo($usuario, new \DateTimeImmutable('today')) === []) {
            return;
        }

        $event->setResponse(new RedirectResponse($this->urls->generate('trabalho_externo_index')));
    }
}
