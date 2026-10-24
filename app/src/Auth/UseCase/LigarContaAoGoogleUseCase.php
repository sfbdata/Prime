<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\DTO\IdentidadeDoGoogle;
use App\Auth\Event\EmailDaContaConfirmado;
use App\Auth\Repository\ConfirmacaoEmailRepository;
use App\Entity\Auth\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * História: a pessoa entrou com o Google numa conta que já existe. Na primeira vez, a conta fica ligada a esta conta
 * Google (`sub`), e o login seguinte acha por ele. Como o Google provou o e-mail, um e-mail ainda "a confirmar"
 * (cadastro sem o clique no link) fica confirmado, pelo mesmo UPDATE condicional do clique e do código da senha; só
 * quem confirmou agora despacha o EmailDaContaConfirmado, depois do commit (decisões do dono, spec entrar-com-google).
 *
 * Decisão 9: quem confirma agora tem a senha antiga trocada por uma inutilizável na mesma transação. A conta "a
 * confirmar" pode ter sido criada por um terceiro com o e-mail desta pessoa; a senha dele não pode passar a valer
 * porque o Google confirmou o e-mail. Para criar uma senha, a dona usa o "Esqueceu a senha?".
 */
final class LigarContaAoGoogleUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConfirmacaoEmailRepository $confirmacoes,
        private readonly EventDispatcherInterface $eventos,
        private readonly LoggerInterface $logger,
        private readonly UserPasswordHasherInterface $senhas,
    ) {
    }

    public function executar(User $usuario, IdentidadeDoGoogle $identidade): void
    {
        $confirmouAgora = $this->em->wrapInTransaction(function () use ($usuario, $identidade): bool {
            $usuario->ligarAoGoogle($identidade->sub);
            $confirmou = $this->confirmacoes->confirmarEmailDaConta($usuario);
            if ($confirmou) {
                // Em hexadecimal porque o bcrypt recusa o byte nulo; ninguém conhece esta senha.
                $usuario->setPassword($this->senhas->hashPassword($usuario, bin2hex(random_bytes(32))));
            }
            $usuario->confirmarEmail();
            $this->em->flush();

            return $confirmou;
        });
        if (true === $confirmouAgora) {
            $this->despacharEmailConfirmado((int) $usuario->getId());
        }
    }

    /** Depois do commit: um ouvinte com defeito não pode virar erro na entrada. */
    private function despacharEmailConfirmado(int $userId): void
    {
        try {
            $this->eventos->dispatch(new EmailDaContaConfirmado($userId));
        } catch (\Throwable $e) {
            $this->logger->error('Um ouvinte de EmailDaContaConfirmado falhou.', ['exception' => $e, 'userId' => $userId]);
        }
    }
}
