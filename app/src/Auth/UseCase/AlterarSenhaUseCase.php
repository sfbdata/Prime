<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\Repository\RedefinicaoSenhaRepository;
use App\Entity\Auth\User;
use App\Repository\UserRepository;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Troca de senha do usuário autenticado, no perfil.
 *
 * Exige a senha atual: sem isso, uma sessão sequestrada (máquina destravada, cookie
 * roubado) vira tomada permanente da conta com dois cliques.
 */
final class AlterarSenhaUseCase
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly RedefinicaoSenhaRepository $redefinicaoRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {}

    public function executar(User $user, string $senhaAtual, string $novaSenha): void
    {
        if (!$this->passwordHasher->isPasswordValid($user, $senhaAtual)) {
            throw new \DomainException('Senha atual incorreta.');
        }

        if ($senhaAtual === $novaSenha) {
            throw new \DomainException('A nova senha precisa ser diferente da atual.');
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $novaSenha));

        // Trocar a senha tem que matar os links de redefinição pendentes. Sem isto: quem
        // teve acesso momentâneo ao e-mail da vítima pede um link, guarda; a vítima
        // percebe e troca a senha pelo perfil; o link guardado continua valendo por até
        // 1h e passa por cima da troca. A troca de senha precisa ser a última palavra.
        $this->redefinicaoRepository->invalidarPendentesDoUsuario($user);

        $this->userRepository->salvar($user, flush: true);
    }
}
