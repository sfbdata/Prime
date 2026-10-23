<?php

declare(strict_types=1);

namespace App\Profile\Service;

use App\Entity\Auth\User;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Quando a janela "Como devemos tratar você?" aparece (spec genero-no-cadastro, regra 3): para quem está logado e
 * ainda não tem o gênero preenchido (nulo, sem perfil ou o legado `nao_informado`). O X vale só para a sessão: a
 * marca fica na sessão, com o id de quem fechou, e some no logout; no próximo login a janela volta.
 */
final class JanelaDoGenero
{
    public const CHAVE_NA_SESSAO = 'perfil_genero_janela_fechada';

    public static function deveAparecer(User $user, ?SessionInterface $sessao): bool
    {
        if (self::preenchido($user)) {
            return false;
        }

        return $sessao === null || $sessao->get(self::CHAVE_NA_SESSAO) !== $user->getId();
    }

    public static function fechar(User $user, SessionInterface $sessao): void
    {
        $sessao->set(self::CHAVE_NA_SESSAO, $user->getId());
    }

    private static function preenchido(User $user): bool
    {
        return $user->getProfile()?->getGenero()?->preenchido() === true;
    }
}
