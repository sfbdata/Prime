<?php

declare(strict_types=1);

namespace App\Tenant\Service;

use App\Entity\Auth\User;
use App\Shared\Email\IdentidadeDeEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

/** E-mail com o código da exclusão da conta (PR 5). Espelha o RedefinicaoSenhaMailer. */
final class CodigoDeExclusaoMailer implements CodigoDeExclusaoMailerInterface
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly IdentidadeDeEmail $identidadeDeEmail,
    ) {
    }

    public function enviar(User $usuario, string $escritorio, string $codigo): void
    {
        $html = $this->twig->render('email/codigo_exclusao_conta.html.twig', [
            'nome' => $usuario->getFullName() ?? $usuario->getEmail(),
            'escritorio' => $escritorio,
            'codigo' => $codigo,
        ]);

        $email = (new Email())
            ->to((string) $usuario->getEmail())
            ->html($html);
        $this->identidadeDeEmail->aplicar($email, 'Código para excluir a conta');

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            throw new \RuntimeException('Falha ao enviar o código de exclusão da conta: ' . $e->getMessage(), previous: $e);
        }
    }
}
