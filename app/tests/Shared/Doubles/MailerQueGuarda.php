<?php

declare(strict_types=1);

namespace App\Tests\Shared\Doubles;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

/** Guarda os e-mails enviados; com $falhar, recusa como um SMTP fora do ar. */
final class MailerQueGuarda implements MailerInterface
{
    /** @var list<Email> */
    public array $enviados = [];

    public function __construct(private readonly bool $falhar = false)
    {
    }

    public function send(RawMessage $message, ?Envelope $envelope = null): void
    {
        if ($this->falhar) {
            throw new TransportException('Expected response code "250" but got "421".');
        }

        \assert($message instanceof Email);
        $this->enviados[] = $message;
    }
}
