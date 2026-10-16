<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\Shared\Email\IdentidadeDeEmail;
use App\TrabalhoExterno\Entity\ConviteDemanda;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * O e-mail do convite do Trabalho Externo. O token em claro só passa por aqui (o banco guarda o
 * hash). Mesmo padrão e mesma identidade de remetente do `ConviteMailer`.
 */
final class TrabalhoExternoMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urls,
        private readonly IdentidadeDeEmail $identidade,
    ) {
    }

    public function enviarConvite(ConviteDemanda $convite, string $token): void
    {
        $demanda = $convite->getDemanda();
        $link = $this->urls->generate('trabalho_externo_convite', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);

        $email = (new Email())
            ->to($convite->getEmail())
            ->html($this->twig->render('email/trabalho_externo_convite.html.twig', [
                'convite' => $convite,
                'servico' => $demanda->getTipoServico(),
                'local' => $demanda->getCidadeExecucao() . '/' . $demanda->getUfExecucao(),
                'link' => $link,
            ]));
        $this->identidade->aplicar($email, 'Convite para uma demanda de trabalho externo');

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException('Falha ao enviar o convite do Trabalho Externo: ' . $e->getMessage(), previous: $e);
        }
    }
}
