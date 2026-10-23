<?php

declare(strict_types=1);

namespace App\Profile\Twig;

use App\Entity\Auth\User;
use App\Profile\Service\JanelaDoGenero;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `pedir_genero()`: o parcial `layout/_globais/genero.html.twig` pergunta se mostra a janela "Como devemos tratar
 * você?" (spec genero-no-cadastro, regra 3). Só lê o usuário logado e a sessão; não consulta o banco além do perfil
 * que já vem com o User.
 */
final class JanelaDoGeneroExtension extends AbstractExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('pedir_genero', $this->pedir(...)),
        ];
    }

    public function pedir(): bool
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return false;
        }
        $request = $this->requestStack->getCurrentRequest();

        return JanelaDoGenero::deveAparecer($user, $request?->hasSession() === true ? $request->getSession() : null);
    }
}
