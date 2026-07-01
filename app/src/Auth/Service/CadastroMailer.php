<?php

declare(strict_types=1);

namespace App\Auth\Service;

use App\Auth\Entity\CadastroPendente;
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
        private readonly string $mailerFrom,
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
            ->from($this->mailerFrom)
            ->to($cadastro->getEmail())
            ->subject('Confirme seu cadastro no BlueJus')
            ->html($html);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException(
                'Falha ao enviar e-mail de confirmação de cadastro: ' . $e->getMessage(),
                previous: $e,
            );
        }
    }
}
