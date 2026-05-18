<?php

declare(strict_types=1);

namespace App\Auth\Service;

use App\Entity\Auth\Invitation;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

final class ConviteMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $mailerFrom,
    ) {
    }

    public function enviarConvitePlataforma(Invitation $invitation): void
    {
        $link = $this->urlGenerator->generate(
            'auth_aceite_convite',
            ['token' => $invitation->getToken()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $html = $this->twig->render('email/convite_plataforma.html.twig', [
            'invitation' => $invitation,
            'link' => $link,
        ]);

        $email = (new Email())
            ->from($this->mailerFrom)
            ->to($invitation->getEmail())
            ->subject('Você foi convidado para o BlueJus')
            ->html($html);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException(
                'Falha ao enviar convite de plataforma: ' . $e->getMessage(),
                previous: $e,
            );
        }
    }

    public function enviarConviteEscritorio(Invitation $invitation): void
    {
        $link = $this->urlGenerator->generate(
            'auth_aceite_convite',
            ['token' => $invitation->getToken()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $html = $this->twig->render('email/convite_escritorio.html.twig', [
            'invitation' => $invitation,
            'link' => $link,
        ]);

        $email = (new Email())
            ->from($this->mailerFrom)
            ->to($invitation->getEmail())
            ->subject('Você foi convidado para colaborar em um escritório no BlueJus')
            ->html($html);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException(
                'Falha ao enviar convite de escritório: ' . $e->getMessage(),
                previous: $e,
            );
        }
    }
}
