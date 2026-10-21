<?php

declare(strict_types=1);

namespace App\Auth\Service;

use App\Entity\Auth\User;
use App\Shared\Email\IdentidadeDeEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * E-mails de redefinição de senha: o código de 6 números (desenho 10) e o link do fluxo
 * antigo. Espelha o CadastroMailer.
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
        private readonly IdentidadeDeEmail $identidadeDeEmail,
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
            ->to((string) $user->getEmail())
            ->html($html);
        $this->identidadeDeEmail->aplicar($email, 'Redefinição de senha no BlueJus');

        $this->enviar($email);
    }

    public function enviarCodigo(User $user, string $codigo): void
    {
        $html = $this->twig->render('email/codigo_senha.html.twig', [
            'nome'   => $user->getFullName() ?? $user->getEmail(),
            'codigo' => $codigo,
        ]);

        $email = (new Email())
            ->to((string) $user->getEmail())
            ->html($html);
        $this->identidadeDeEmail->aplicar($email, 'Seu código BlueJus');

        // O assunto é o do desenho 10, sem o prefixo institucional "[BlueJus]": o código
        // aparece já na notificação do celular, e o nome BlueJus está no próprio texto.
        $email->subject('Seu código BlueJus: ' . $codigo);

        $this->enviar($email);
    }

    private function enviar(Email $email): void
    {
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
