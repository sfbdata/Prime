<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\Entity\RedefinicaoSenha;
use App\Auth\Repository\RedefinicaoSenhaRepository;
use App\Entity\Auth\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Passo 2 da recuperação de senha: consome o token e grava a senha nova.
 *
 * Trocar o hash da senha derruba sozinho as sessões antigas e os cookies "lembrar-me":
 * o ContextListener do Symfony compara o hash guardado na sessão com o do banco, e a
 * assinatura do remember-me inclui a senha por padrão (`signature_properties`). Não há
 * código nosso para isso — mas há teste, porque é comportamento do qual dependemos.
 */
final class RedefinirSenhaUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RedefinicaoSenhaRepository $redefinicaoRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {}

    public function executar(string $token, string $novaSenha): User
    {
        $redefinicao = $this->redefinicaoRepository->encontrarPorToken($token);

        if ($redefinicao === null) {
            throw new \DomainException('Link inválido. Peça uma nova redefinição de senha.');
        }

        if ($redefinicao->isUsado()) {
            throw new \DomainException('Este link já foi usado. Peça uma nova redefinição de senha.');
        }

        if ($redefinicao->isExpirado()) {
            throw new \DomainException('Este link expirou. Peça uma nova redefinição de senha.');
        }

        $user = $redefinicao->getUser();

        // A conta pode ter sido desativada entre o pedido e o clique.
        if ($user->isActive() === false) {
            throw new \DomainException('Esta conta está desativada. Fale com o administrador do seu escritório.');
        }

        // As três escritas (consumir o token, apagar os pendentes, gravar a senha) vão numa
        // transação só. Sem ela, um erro no flush deixaria o token queimado e a senha
        // inalterada — a pessoa perderia o link sem trocar nada.
        return $this->em->wrapInTransaction(function () use ($redefinicao, $user, $novaSenha): User {
            // Consome ANTES de trocar a senha: o `WHERE usado_em IS NULL` faz o banco decidir
            // quem chegou primeiro quando dois POSTs chegam com o mesmo token.
            $agora = new \DateTimeImmutable();

            if (!$this->redefinicaoRepository->consumir($redefinicao, $agora)) {
                throw new \DomainException('Este link já foi usado. Peça uma nova redefinição de senha.');
            }

            $redefinicao->marcarUsado($agora);

            $user->setPassword($this->passwordHasher->hashPassword($user, $novaSenha));

            $this->redefinicaoRepository->invalidarPendentesDoUsuario($user, exceto: $redefinicao);

            // A marca de uso já foi ao banco no consumo atômico; este flush grava a senha nova.
            $this->redefinicaoRepository->salvar($redefinicao, flush: true);

            return $user;
        });
    }
}
