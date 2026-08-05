<?php

declare(strict_types=1);

namespace App\Auth\Service;

use App\Entity\Auth\User;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * E-mail com o link de redefinição de senha. Espelha o CadastroMailer.
 *
 * O link é ABSOLUTE_URL: o host vem do `DEFAULT_URI` (config/packages/routing.yaml).
 * Se esse valor estiver errado no ambiente, o link nasce quebrado e o usuário não
 * consegue voltar à conta — é o item nº 1 do checklist de produção da spec.
 */
final class RedefinicaoSenhaMailer implements RedefinicaoSenhaMailerInterface
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $mailerFrom,
    ) {
    }

    public function enviarLink(User $user, string $token): void
    {
        $link = $this->urlGenerator->generate(
            'auth_senha_redefinir',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $html = $this->twig->render('email/redefinicao_senha.html.twig', [
            'nome' => $user->getFullName() ?? $user->getEmail(),
            'link' => $link,
        ]);

        $email = (new Email())
            ->from($this->mailerFrom)
            ->to((string) $user->getEmail())
            ->subject('Redefinição de senha no BlueJus')
            ->html($html);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException(
                'Falha ao enviar e-mail de redefinição de senha: ' . $e->getMessage(),
                previous: $e,
            );
        }
    }
}
