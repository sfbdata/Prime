<?php

declare(strict_types=1);

namespace App\Auth\Service;

use App\Entity\Auth\User;

/**
 * Envio do link de redefinição de senha.
 *
 * Existe como interface porque o UseCase que a usa decide, sozinho, se envia ou não —
 * e essa decisão é o que sustenta a resposta neutra (RN02). O teste precisa observar
 * essa decisão sem SMTP no caminho.
 */
interface RedefinicaoSenhaMailerInterface
{
    /**
     * @param string $token token EM CLARO, que vai na URL do e-mail (o banco guarda só o hash)
     *
     * @throws \RuntimeException se o transporte falhar
     */
    public function enviarLink(User $user, string $token): void;
}
