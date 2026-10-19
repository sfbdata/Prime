<?php

declare(strict_types=1);

namespace App\Auth\Service;

use App\Auth\Entity\CadastroPendente;
use App\Entity\Auth\User;
use App\Shared\Email\IdentidadeDeEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

final class CadastroMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly IdentidadeDeEmail $identidadeDeEmail,
    ) {
    }

    public function enviarConfirmacao(CadastroPendente $cadastro): void
    {
        $link = $this->urlGenerator->generate(
            'auth_cadastro_confirmar',
            ['token' => $cadastro->getToken()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $html = $this->twig->render('email/cadastro_confirmacao.html.twig', [
            'cadastro' => $cadastro,
            'link' => $link,
        ]);

        $email = (new Email())
            ->to($cadastro->getEmail())
            ->html($html);
        $this->identidadeDeEmail->aplicar($email, 'Confirme seu cadastro no BlueJus');

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException(
                'Falha ao enviar e-mail de confirmação de cadastro: ' . $e->getMessage(),
                previous: $e,
            );
        }
    }

    /**
     * Confirmação do e-mail de uma conta que JÁ existe (cadastro do desenho 10). O token chega em claro só aqui,
     * para entrar no link; o banco guarda o hash.
     */
    public function enviarConfirmacaoDeEmail(User $user, string $token): void
    {
        $link = $this->urlGenerator->generate(
            'auth_cadastro_confirmar_email',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $email = (new Email())
            ->to((string) $user->getEmail())
            ->html($this->twig->render('auth/cadastro/email_confirmacao.html.twig', [
                'nome' => $user->getFullName(),
                'link' => $link,
            ]));
        $this->identidadeDeEmail->aplicar($email, 'Confirme seu e-mail no BlueJus');

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException('Falha ao enviar e-mail de confirmação da conta: ' . $e->getMessage(), previous: $e);
        }
    }
}
