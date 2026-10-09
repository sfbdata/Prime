<?php

declare(strict_types=1);

namespace App\Shared\Email;

use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\ExceptionInterface;

final class IdentidadeDeEmail
{
    private const PREFIXO_ASSUNTO = '[BlueJus]';

    private readonly Address $from;
    private readonly ?Address $replyTo;

    public function __construct(
        string $mailerFrom,
        string $mailerReplyTo,
    ) {
        $this->from = $this->validarEndereco($mailerFrom, 'MAILER_FROM');

        $mailerReplyTo = trim($mailerReplyTo);
        $this->replyTo = $mailerReplyTo === ''
            ? null
            : $this->validarEndereco($mailerReplyTo, 'MAILER_REPLY_TO');
    }

    public function aplicar(Email $email, string $assunto): Email
    {
        $assunto = trim($assunto);
        if (!str_starts_with($assunto, self::PREFIXO_ASSUNTO)) {
            $assunto = self::PREFIXO_ASSUNTO . ' ' . $assunto;
        }

        $email
            ->from($this->from)
            ->subject($assunto);

        if ($this->replyTo !== null) {
            $email->replyTo($this->replyTo);
        }

        return $email;
    }

    private function validarEndereco(string $endereco, string $variavel): Address
    {
        try {
            return Address::create($endereco);
        } catch (ExceptionInterface) {
            throw new \InvalidArgumentException(
                sprintf('Configuração de e-mail inválida em %s.', $variavel),
            );
        }
    }
}
