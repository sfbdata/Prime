<?php

declare(strict_types=1);

namespace App\Auth\Service;

use App\Auth\DTO\IdentidadeDoFacebook;
use App\Auth\Exception\EntradaPeloFacebookRecusadaException;
use App\Entity\Auth\User;
use App\Repository\UserRepository;

/**
 * Qual conta do BlueJus é a da identidade do Facebook, sem gravar nada. SÓ pelo `facebook_id`: o Facebook não prova
 * e-mail, então o e-mail dele nunca casa com conta existente (anti-tomada de conta, spec login-cupom-e-facebook).
 * Sem conta ligada a este `id`: SEM_CONTA (o autenticador leva ao cadastro pelo Facebook).
 */
final class ContaDoFacebook
{
    public function __construct(private readonly UserRepository $usuarios)
    {
    }

    public function localizar(IdentidadeDoFacebook $identidade): User
    {
        $conta = $this->usuarios->findOneBy(['facebookId' => $identidade->id]);
        if (!$conta instanceof User) {
            throw new EntradaPeloFacebookRecusadaException(EntradaPeloFacebookRecusadaException::SEM_CONTA);
        }

        return $conta;
    }
}
