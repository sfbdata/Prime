<?php

declare(strict_types=1);

namespace App\Auth\Service;

use App\Auth\Entity\CadastroPendente;
use App\Auth\Event\ConfirmacaoDeCadastroEnviada;
use App\Shared\Email\IdentidadeDeEmail;
use Psr\EventDispatcher\EventDispatcherInterface;
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
        private readonly EventDispatcherInterface $eventos,
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

        // Depois do envio, para os "Últimos disparos" do Comercial (comercial-e-emails.md §3.5, `cad`). O ouvinte
        // de lá captura qualquer falha: o cadastro nunca recebe erro por causa do registro.
        $this->eventos->dispatch(new ConfirmacaoDeCadastroEnviada($cadastro->getEmail(), (string) $email->getSubject()));
    }
}
