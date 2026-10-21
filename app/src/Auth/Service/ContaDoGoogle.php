<?php

declare(strict_types=1);

namespace App\Auth\Service;

use App\Auth\DTO\IdentidadeDoGoogle;
use App\Auth\Exception\EntradaPeloGoogleRecusadaException;
use App\Entity\Auth\User;
use App\Repository\UserRepository;

/**
 * Qual conta do BlueJus é a da identidade do Google (spec entrar-com-google, seção 2), sem gravar nada:
 * 1. a que já tem este `sub`;
 * 2. senão, a do mesmo e-mail (o Google já confirmou o e-mail), ignorando a caixa; com duas contas que diferem só na
 *    caixa, a que bate exatamente; ligada a OUTRO `sub`, recusa;
 * 3. nenhuma: recusa (entrega 1: o cadastro pelo Google é a entrega 2).
 */
final class ContaDoGoogle
{
    public function __construct(private readonly UserRepository $usuarios)
    {
    }

    public function localizar(IdentidadeDoGoogle $identidade): User
    {
        $peloSub = $this->usuarios->findOneBy(['googleSub' => $identidade->sub]);
        if ($peloSub instanceof User) {
            return $peloSub;
        }
        $peloEmail = $this->peloEmail($identidade->email);
        if (null === $peloEmail) {
            throw new EntradaPeloGoogleRecusadaException(EntradaPeloGoogleRecusadaException::SEM_CONTA);
        }
        if (null !== $peloEmail->getGoogleSub()) {
            throw new EntradaPeloGoogleRecusadaException(EntradaPeloGoogleRecusadaException::OUTRA_CONTA_GOOGLE);
        }

        return $peloEmail;
    }

    private function peloEmail(string $email): ?User
    {
        $encontrados = $this->usuarios->encontrarPorEmailIgnorandoCaixa($email);
        if (count($encontrados) <= 1) {
            return $encontrados[0] ?? null;
        }
        foreach ($encontrados as $usuario) {
            if ($usuario->getEmail() === $email) {
                return $usuario;
            }
        }

        return null;
    }
}
