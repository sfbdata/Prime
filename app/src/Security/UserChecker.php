<?php

namespace App\Security;

use App\Auth\Service\ContaAConfirmar;
use App\Entity\Auth\User;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\Exception\DisabledException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class UserChecker implements UserCheckerInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ContaAConfirmar $contaAConfirmar,
    ) {
    }

    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (!$user->isActive()) {
            throw new DisabledException();
        }
    }

    /**
     * Conta com o e-mail "a confirmar" não entra (spec `cadastro-exige-confirmacao.md`). Roda DEPOIS da senha
     * conferida, no formulário e no lembrar-me: quem não tem a senha recebe o erro de credencial comum e não fica
     * sabendo que a conta existe. Só então o id vai para a sessão, para o "Reenviar o link de confirmação" público.
     */
    public function checkPostAuth(UserInterface $user): void
    {
        if (!$user instanceof User || $user->isEmailConfirmado()) {
            return;
        }

        $request = $this->requestStack->getMainRequest();
        if ($request !== null && $request->hasSession()) {
            $this->contaAConfirmar->guardar($request->getSession(), $user);
        }

        throw new CustomUserMessageAccountStatusException(ContaAConfirmar::MENSAGEM_LOGIN, ['{email}' => (string) $user->getEmail()]);
    }
}
