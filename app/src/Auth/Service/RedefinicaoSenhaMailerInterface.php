<?php

declare(strict_types=1);

namespace App\Auth\Service;

use App\Entity\Auth\User;

/**
 * Envio do link (fluxo antigo) e do código (desenho 10) de redefinição de senha.
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

    /**
     * Envia o código de 6 números da senha por código (desenho 10).
     *
     * @param string $codigo código EM CLARO (o banco guarda só o HMAC dele)
     *
     * @throws \RuntimeException se o transporte falhar
     */
    public function enviarCodigo(User $user, string $codigo): void;
}
