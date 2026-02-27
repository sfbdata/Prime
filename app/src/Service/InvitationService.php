<?php

namespace App\Service;

use App\Entity\Auth\User;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class InvitationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MailerInterface $mailer,
        private UrlGeneratorInterface $urlGenerator
    ) {
    }

    /**
     * @return array{sent: bool, duplicateEmail: bool, link: ?string, error: ?string}
     */
    public function sendInvitation(User $user, string $subject = 'Convite para acessar o sistema'): array
    {
        $token = bin2hex(random_bytes(32));
        $user->setInvitationToken($token);
        $user->setIsActive(false);

        try {
            $this->entityManager->persist($user);
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            return [
                'sent' => false,
                'duplicateEmail' => true,
                'link' => null,
                'error' => 'E-mail já cadastrado.',
            ];
        }

        $link = $this->urlGenerator->generate(
            'register_confirm',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $fullName = $user->getFullName() ?? 'Usuário';

        $email = (new Email())
            ->from('jusprime.samuel@gmail.com')
            ->to((string) $user->getEmail())
            ->subject($subject)
            ->text("Olá {$fullName},\n\nVocê foi convidado para acessar o sistema.\nClique no link abaixo para criar sua senha:\n\n{$link}");

        try {
            $this->mailer->send($email);

            return [
                'sent' => true,
                'duplicateEmail' => false,
                'link' => $link,
                'error' => null,
            ];
        } catch (TransportExceptionInterface $exception) {
            return [
                'sent' => false,
                'duplicateEmail' => false,
                'link' => $link,
                'error' => $exception->getMessage(),
            ];
        }
    }
}