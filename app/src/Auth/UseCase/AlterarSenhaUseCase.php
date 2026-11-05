<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\Repository\RedefinicaoSenhaRepository;
use App\Entity\Auth\User;
use App\Repository\UserRepository;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Troca de senha do usuário autenticado, no perfil.
 *
 * Exige a senha atual: sem isso, uma sessão sequestrada (máquina destravada, cookie
 * roubado) vira tomada permanente da conta com dois cliques. A conferência tem limite por conta
 * (`perfil_senha_atual`, 5 em 15 min, chave = id do usuário), consumido antes do isPasswordValid:
 * sem ele, quem tem a sessão testava a senha atual em laço (auditoria 3.27).
 */
final class AlterarSenhaUseCase
{
    public const MUITAS_TENTATIVAS = 'Muitas tentativas. Tente de novo em alguns minutos.';

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly RedefinicaoSenhaRepository $redefinicaoRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly RateLimiterFactoryInterface $perfilSenhaAtualLimiter,
    ) {}

    public function executar(User $user, string $senhaAtual, string $novaSenha): void
    {
        if (!$this->perfilSenhaAtualLimiter->create((string) $user->getId())->consume()->isAccepted()) {
            throw new \DomainException(self::MUITAS_TENTATIVAS);
        }

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
