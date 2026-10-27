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

final class AvisoDeAcessoNovoMailer implements AvisoDeAcessoNovoMailerInterface
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly IdentidadeDeEmail $identidadeDeEmail,
    ) {
    }

    public function avisar(User $user, \DateTimeImmutable $quando, NavegadorDoAcesso $navegador): void
    {
        $link = $this->urlGenerator->generate('auth_senha_esqueci', [], UrlGeneratorInterface::ABSOLUTE_URL);

        $email = (new Email())
            ->to((string) $user->getEmail())
            ->html($this->twig->render('email/acesso_novo.html.twig', [
                'nome' => $user->getFullName(),
                'quando' => $quando,
                'navegador' => $navegador->rotuloDoNavegador(),
                'sistema' => $navegador->sistema,
                'link' => $link,
            ]));
        $this->identidadeDeEmail->aplicar($email, 'Novo acesso à sua conta');

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException('Falha ao enviar o aviso de acesso novo: ' . $e->getMessage(), previous: $e);
        }
    }
}
