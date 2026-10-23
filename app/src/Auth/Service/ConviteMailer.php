<?php

declare(strict_types=1);

namespace App\Auth\Service;

use App\Entity\Auth\Invitation;
use App\Shared\Email\IdentidadeDeEmail;
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
        private readonly IdentidadeDeEmail $identidadeDeEmail,
    ) {
    }

    /** O token puro vem do caso de uso que o gerou (`ConviteEmitido`): o banco só tem o hash. */
    public function enviarConvitePlataforma(Invitation $invitation, #[\SensitiveParameter] string $token): void
    {
        $link = $this->urlGenerator->generate(
            'auth_aceite_convite',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $html = $this->twig->render('email/convite_plataforma.html.twig', [
            'invitation' => $invitation,
            'link' => $link,
        ]);

        $email = (new Email())
            ->to($invitation->getEmail())
            ->html($html);
        $this->identidadeDeEmail->aplicar($email, 'Você foi convidado para o BlueJus');

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException(
                'Falha ao enviar convite de plataforma: ' . $e->getMessage(),
                previous: $e,
            );
        }
    }

    /** O token puro vem do caso de uso que o gerou (`ConviteEmitido`): o banco só tem o hash. */
    public function enviarConviteEscritorio(Invitation $invitation, #[\SensitiveParameter] string $token): void
    {
        $link = $this->urlGenerator->generate(
            'auth_aceite_convite',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        $html = $this->twig->render('email/convite_escritorio.html.twig', [
            'invitation' => $invitation,
            'link' => $link,
        ]);

        $email = (new Email())
            ->to($invitation->getEmail())
            ->html($html);
        $this->identidadeDeEmail->aplicar($email, 'Você foi convidado para colaborar em um escritório no BlueJus');

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
