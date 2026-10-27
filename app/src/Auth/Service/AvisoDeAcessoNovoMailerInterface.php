<?php

declare(strict_types=1);

namespace App\Auth\Service;

use App\Entity\Auth\User;

/** Envio do e-mail "Novo acesso à sua conta". Interface para o UseCase ser testado sem SMTP. */
interface AvisoDeAcessoNovoMailerInterface
{
    /** `$quando` já vem no fuso do sistema. Lança \RuntimeException se o transporte falhar. */
    public function avisar(User $user, \DateTimeImmutable $quando, NavegadorDoAcesso $navegador): void;
}
