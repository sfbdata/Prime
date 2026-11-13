<?php

declare(strict_types=1);

namespace App\Voto\Service;

use App\Shared\Email\IdentidadeDeEmail;
use App\Shared\Service\GeradorDePdf;
use App\Voto\DTO\ComprovanteDeParticipacao;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

final class CorreioDoComprovantePorEmail implements CorreioDoComprovante
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly IdentidadeDeEmail $identidade,
        private readonly GeradorDePdf $pdf,
    ) {
    }

    public function enviar(string $destinatario, ComprovanteDeParticipacao $comprovante): void
    {
        $dados = ['comprovante' => $comprovante];
        $anexo = $this->pdf->gerar($this->twig->render('voto/cedula/comprovante_pdf.html.twig', $dados));
        $email = (new Email())->to($destinatario)
            ->html($this->twig->render('email/voto_comprovante.html.twig', $dados))
            ->text($this->twig->render('email/voto_comprovante.txt.twig', $dados))
            ->attach($anexo, $comprovante->codigo . '.pdf', 'application/pdf');
        $this->identidade->aplicar($email, 'Comprovante de participação');
        $this->mailer->send($email);
    }
}
