<?php

declare(strict_types=1);

namespace App\Cliente\Exception;

/** Outra tela mudou os e-mails do cliente ao mesmo tempo e o banco recusou (UNIQUE): 409, nunca 500. */
final class EmailDoClienteEmConflitoException extends \DomainException
{
    public const MENSAGEM = 'Os e-mails do cliente mudaram em outra tela ao mesmo tempo. Recarregue e tente de novo.';

    public function __construct(?\Throwable $anterior = null)
    {
        parent::__construct(self::MENSAGEM, 0, $anterior);
    }
}
