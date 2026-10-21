<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Support;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

/** Mailer de teste que recusa todo envio, como um SMTP fora do ar (a mensagem cita o destinatário, como os reais). */
final class MailerQueFalha implements MailerInterface
{
    public int $tentativas = 0;

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        ++$this->tentativas;

        throw new TransportException('Expected response code "250" but got "550" for <ana@exemplo.com>.');
    }
}
