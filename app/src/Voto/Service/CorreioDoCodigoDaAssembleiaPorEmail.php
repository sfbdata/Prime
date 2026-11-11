<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Shared\Email\IdentidadeDeEmail;
use App\Voto\Entity\UnidadeDaAssembleia;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

final class CorreioDoCodigoDaAssembleiaPorEmail implements CorreioDoCodigoDaAssembleia
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly IdentidadeDeEmail $identidade,
    ) {
    }

    public function enviar(UnidadeDaAssembleia $unidade, string $codigo): void
    {
        $email = (new Email())->to((string) $unidade->getEmail())->html($this->twig->render('email/voto_assembleia_codigo.html.twig', [
            'assembleia' => $unidade->getAssembleia()->getTitulo(), 'codigo' => $codigo,
        ]));
        $this->identidade->aplicar($email, 'Código de acesso à votação');
        $this->mailer->send($email);
    }
}
